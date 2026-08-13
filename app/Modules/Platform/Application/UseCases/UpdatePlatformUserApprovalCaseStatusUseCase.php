<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\PlatformUserApprovalCaseRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\PlatformUserApprovalCaseStatus;
use DomainException;

class UpdatePlatformUserApprovalCaseStatusUseCase
{
    public function __construct(
        private readonly PlatformUserApprovalCaseRepositoryInterface $platformUserApprovalCaseRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $reason = null, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->platformUserApprovalCaseRepository->findCaseById($id);
        if ($existing === null) {
            return null;
        }

        $normalizedStatus = strtolower(trim($status));
        if (! in_array($normalizedStatus, PlatformUserApprovalCaseStatus::statusTransitionValues(), true)) {
            throw new DomainException('Invalid approval case status transition target.');
        }

        $currentStatus = strtolower((string) ($existing['status'] ?? PlatformUserApprovalCaseStatus::DRAFT->value));
        $this->assertStatusTransitionAllowed($currentStatus, $normalizedStatus);

        $normalizedReason = $this->nullableTrimmedValue($reason);

        $payload = [
            'status' => $normalizedStatus,
            'decision_reason' => $normalizedStatus === PlatformUserApprovalCaseStatus::CANCELLED->value
                ? $normalizedReason
                : ($existing['decision_reason'] ?? null),
        ];

        if ($normalizedStatus === PlatformUserApprovalCaseStatus::SUBMITTED->value
            && ($existing['submitted_at'] ?? null) === null) {
            $payload['submitted_at'] = now();
        }

        $updated = $this->platformUserApprovalCaseRepository->updateCase($id, $payload);
        if ($updated === null) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $reasonRequired = $normalizedStatus === PlatformUserApprovalCaseStatus::CANCELLED->value;
            $this->platformUserApprovalCaseRepository->writeAuditLog(
                approvalCaseId: $id,
                action: 'platform.user-approval-case.status.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'transition' => [
                        'from' => $existing['status'] ?? null,
                        'to' => $updated['status'] ?? null,
                    ],
                    'reason_required' => $reasonRequired,
                    'reason_provided' => trim((string) ($updated['decision_reason'] ?? '')) !== '',
                ],
            );
        }

        return $this->platformUserApprovalCaseRepository->findCaseById($id) ?? $updated;
    }

    private function assertStatusTransitionAllowed(string $currentStatus, string $nextStatus): void
    {
        if ($currentStatus === $nextStatus) {
            return;
        }

        if (in_array($currentStatus, PlatformUserApprovalCaseStatus::decisionValues(), true)) {
            throw new DomainException('Decided approval cases cannot change status.');
        }

        $allowedTransitions = [
            PlatformUserApprovalCaseStatus::DRAFT->value => [
                PlatformUserApprovalCaseStatus::SUBMITTED->value,
                PlatformUserApprovalCaseStatus::CANCELLED->value,
            ],
            PlatformUserApprovalCaseStatus::SUBMITTED->value => [
                PlatformUserApprovalCaseStatus::CANCELLED->value,
            ],
            PlatformUserApprovalCaseStatus::CANCELLED->value => [],
        ];

        $allowedNextStatuses = $allowedTransitions[$currentStatus] ?? [];
        if (in_array($nextStatus, $allowedNextStatuses, true)) {
            return;
        }

        throw new DomainException('Requested approval case status transition is not allowed.');
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'status',
            'decision_reason',
            'submitted_at',
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
}
