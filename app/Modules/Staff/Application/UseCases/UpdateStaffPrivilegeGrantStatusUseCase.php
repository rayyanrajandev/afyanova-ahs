<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Exceptions\InvalidStaffPrivilegeGrantStatusTransitionException;
use App\Modules\Staff\Application\Exceptions\StaffPrivilegeGrantCredentialingNotReadyException;
use App\Modules\Staff\Application\Services\VerifiedStaffUserEmailGuard;
use App\Modules\Staff\Domain\Repositories\StaffPrivilegeGrantAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffPrivilegeGrantRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffCredentialingState;
use App\Modules\Staff\Domain\ValueObjects\StaffPrivilegeGrantStatus;

class UpdateStaffPrivilegeGrantStatusUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffPrivilegeGrantRepositoryInterface $staffPrivilegeGrantRepository,
        private readonly StaffPrivilegeGrantAuditLogRepositoryInterface $auditLogRepository,
        private readonly GetStaffCredentialingSummaryUseCase $getStaffCredentialingSummaryUseCase,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly VerifiedStaffUserEmailGuard $verifiedStaffUserEmailGuard,
    ) {}

    public function execute(
        string $staffProfileId,
        string $staffPrivilegeGrantId,
        string $status,
        ?string $reason,
        ?int $actorId = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }
        $this->verifiedStaffUserEmailGuard->assertVerified($profile);

        $existing = $this->staffPrivilegeGrantRepository->findByIdForStaffProfile(
            staffProfileId: $staffProfileId,
            id: $staffPrivilegeGrantId,
        );
        if (! $existing) {
            return null;
        }

        $normalizedStatus = in_array($status, StaffPrivilegeGrantStatus::values(), true)
            ? $status
            : StaffPrivilegeGrantStatus::REQUESTED->value;
        $normalizedReason = $this->nullableTrimmedValue($reason);
        $currentStatus = strtolower(trim((string) ($existing['status'] ?? '')));

        if (! $this->canTransition($currentStatus, $normalizedStatus)) {
            throw new InvalidStaffPrivilegeGrantStatusTransitionException(sprintf(
                'Privilege status cannot move from %s to %s.',
                $currentStatus !== '' ? $currentStatus : 'unknown',
                $normalizedStatus,
            ));
        }

        if (
            in_array($normalizedStatus, [
                StaffPrivilegeGrantStatus::APPROVED->value,
                StaffPrivilegeGrantStatus::ACTIVE->value,
            ], true)
            && $currentStatus !== $normalizedStatus
        ) {
            $this->assertCredentialingReady($staffProfileId);
        }

        $workflowTimestamps = $this->buildWorkflowTimestamps(
            currentStatus: $currentStatus,
            nextStatus: $normalizedStatus,
            existing: $existing,
        );
        $governanceFields = $this->buildGovernanceFields(
            currentStatus: $currentStatus,
            nextStatus: $normalizedStatus,
            existing: $existing,
            actorId: $actorId,
            reason: $normalizedReason,
        );

        $updated = $this->staffPrivilegeGrantRepository->update($staffPrivilegeGrantId, [
            'status' => $normalizedStatus,
            'status_reason' => $this->normalizeStatusReason($normalizedStatus, $normalizedReason),
            'updated_by_user_id' => $actorId,
            ...$workflowTimestamps,
            ...$governanceFields,
        ]);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $metadata = [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => in_array(
                    $normalizedStatus,
                    [
                        StaffPrivilegeGrantStatus::SUSPENDED->value,
                        StaffPrivilegeGrantStatus::RETIRED->value,
                    ],
                    true
                ),
                'reason_provided' => $normalizedReason !== null,
                'governance_stage' => $this->governanceStageForStatus($normalizedStatus),
            ];

            $this->auditLogRepository->write(
                staffPrivilegeGrantId: $staffPrivilegeGrantId,
                staffProfileId: $staffProfileId,
                action: 'staff-privilege-grant.status.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: $metadata,
            );
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWorkflowTimestamps(string $currentStatus, string $nextStatus, array $existing): array
    {
        $timestamps = [];

        if (($existing['requested_at'] ?? null) === null) {
            $timestamps['requested_at'] = now();
        }

        if ($nextStatus === StaffPrivilegeGrantStatus::REQUESTED->value) {
            $timestamps['review_started_at'] = null;
            $timestamps['approved_at'] = null;
            $timestamps['activated_at'] = null;

            return $timestamps;
        }

        if (
            $nextStatus === StaffPrivilegeGrantStatus::UNDER_REVIEW->value
            && $currentStatus !== StaffPrivilegeGrantStatus::UNDER_REVIEW->value
        ) {
            $timestamps['review_started_at'] = now();
            $timestamps['approved_at'] = null;
            $timestamps['activated_at'] = null;
        }

        if (
            $nextStatus === StaffPrivilegeGrantStatus::APPROVED->value
            && $currentStatus !== StaffPrivilegeGrantStatus::APPROVED->value
        ) {
            $timestamps['review_started_at'] = $timestamps['review_started_at'] ?? ($existing['review_started_at'] ?? now());
            $timestamps['approved_at'] = now();
            $timestamps['activated_at'] = null;
        }

        if (
            $nextStatus === StaffPrivilegeGrantStatus::ACTIVE->value
            && $currentStatus !== StaffPrivilegeGrantStatus::ACTIVE->value
        ) {
            $timestamps['review_started_at'] = $timestamps['review_started_at'] ?? ($existing['review_started_at'] ?? now());
            $timestamps['approved_at'] = $timestamps['approved_at'] ?? ($existing['approved_at'] ?? now());
            $timestamps['activated_at'] = now();
        }

        return $timestamps;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGovernanceFields(
        string $currentStatus,
        string $nextStatus,
        array $existing,
        ?int $actorId,
        ?string $reason,
    ): array {
        $fields = [];

        if ($nextStatus === StaffPrivilegeGrantStatus::REQUESTED->value) {
            return [
                'reviewer_user_id' => null,
                'review_note' => null,
                'approver_user_id' => null,
                'approval_note' => null,
            ];
        }

        if (
            $nextStatus === StaffPrivilegeGrantStatus::UNDER_REVIEW->value
            && $currentStatus !== StaffPrivilegeGrantStatus::UNDER_REVIEW->value
        ) {
            $fields['reviewer_user_id'] = $actorId;
            $fields['review_note'] = $reason;
            $fields['approver_user_id'] = null;
            $fields['approval_note'] = null;
        }

        if (
            $nextStatus === StaffPrivilegeGrantStatus::APPROVED->value
            && $currentStatus !== StaffPrivilegeGrantStatus::APPROVED->value
        ) {
            $fields['reviewer_user_id'] = $existing['reviewer_user_id'] ?? $actorId;
            $fields['review_note'] = $existing['review_note'] ?? null;
            $fields['approver_user_id'] = $actorId;
            $fields['approval_note'] = $reason;
        }

        return $fields;
    }

    private function normalizeStatusReason(string $status, ?string $reason): ?string
    {
        if ($status === StaffPrivilegeGrantStatus::ACTIVE->value && $reason === null) {
            return null;
        }

        return $reason;
    }

    private function canTransition(string $currentStatus, string $nextStatus): bool
    {
        if ($currentStatus === '' || $currentStatus === $nextStatus) {
            return true;
        }

        return in_array($nextStatus, $this->allowedTransitions($currentStatus), true);
    }

    /**
     * @return array<int, string>
     */
    private function allowedTransitions(string $currentStatus): array
    {
        return match ($currentStatus) {
            StaffPrivilegeGrantStatus::REQUESTED->value => [
                StaffPrivilegeGrantStatus::UNDER_REVIEW->value,
                StaffPrivilegeGrantStatus::RETIRED->value,
            ],
            StaffPrivilegeGrantStatus::UNDER_REVIEW->value => [
                StaffPrivilegeGrantStatus::REQUESTED->value,
                StaffPrivilegeGrantStatus::APPROVED->value,
                StaffPrivilegeGrantStatus::RETIRED->value,
            ],
            StaffPrivilegeGrantStatus::APPROVED->value => [
                StaffPrivilegeGrantStatus::UNDER_REVIEW->value,
                StaffPrivilegeGrantStatus::ACTIVE->value,
                StaffPrivilegeGrantStatus::RETIRED->value,
            ],
            StaffPrivilegeGrantStatus::ACTIVE->value => [
                StaffPrivilegeGrantStatus::SUSPENDED->value,
                StaffPrivilegeGrantStatus::RETIRED->value,
            ],
            StaffPrivilegeGrantStatus::SUSPENDED->value => [
                StaffPrivilegeGrantStatus::ACTIVE->value,
                StaffPrivilegeGrantStatus::RETIRED->value,
            ],
            default => [],
        };
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function assertCredentialingReady(string $staffProfileId): void
    {
        $summary = $this->getStaffCredentialingSummaryUseCase->execute($staffProfileId);
        $state = $summary['credentialing_state'] ?? null;
        if ($state === StaffCredentialingState::READY->value) {
            return;
        }

        $reasons = array_values(array_filter(
            array_map(
                static fn (mixed $value): string => trim((string) $value),
                is_array($summary['blocking_reasons'] ?? null) ? $summary['blocking_reasons'] : [],
            ),
            static fn (string $value): bool => $value !== '',
        ));

        $message = 'Privilege grants cannot be activated until staff credentialing is ready.';
        if ($reasons !== []) {
            $message .= ' '.implode(' ', array_map(
                static fn (string $reason): string => rtrim($reason, '.').'.',
                $reasons,
            ));
        }

        throw new StaffPrivilegeGrantCredentialingNotReadyException($message);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'status',
            'status_reason',
            'requested_at',
            'review_started_at',
            'approved_at',
            'activated_at',
            'reviewer_user_id',
            'review_note',
            'approver_user_id',
            'approval_note',
        ];

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;
            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }

    private function governanceStageForStatus(string $status): ?string
    {
        return match ($status) {
            StaffPrivilegeGrantStatus::UNDER_REVIEW->value => 'review',
            StaffPrivilegeGrantStatus::APPROVED->value => 'approval',
            StaffPrivilegeGrantStatus::ACTIVE->value,
            StaffPrivilegeGrantStatus::SUSPENDED->value,
            StaffPrivilegeGrantStatus::RETIRED->value => 'status',
            default => null,
        };
    }
}
