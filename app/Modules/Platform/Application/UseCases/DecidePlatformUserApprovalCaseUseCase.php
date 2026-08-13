<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\PlatformUserApprovalCaseRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\PlatformUserApprovalCaseStatus;
use DomainException;

class DecidePlatformUserApprovalCaseUseCase
{
    public function __construct(
        private readonly PlatformUserApprovalCaseRepositoryInterface $platformUserApprovalCaseRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $decision, ?string $reason = null, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->platformUserApprovalCaseRepository->findCaseById($id);
        if ($existing === null) {
            return null;
        }

        if (($existing['status'] ?? null) !== PlatformUserApprovalCaseStatus::SUBMITTED->value) {
            throw new DomainException('Only submitted approval cases can be decided.');
        }

        $normalizedDecision = strtolower(trim($decision));
        if (! in_array($normalizedDecision, PlatformUserApprovalCaseStatus::decisionValues(), true)) {
            throw new DomainException('Decision must be approved or rejected.');
        }

        $normalizedReason = $this->nullableTrimmedValue($reason);
        if ($normalizedDecision === PlatformUserApprovalCaseStatus::REJECTED->value && $normalizedReason === null) {
            throw new DomainException('Decision reason is required when rejecting an approval case.');
        }

        if ($actorId !== null && $this->platformUserApprovalCaseRepository->resolveUserInScope($actorId) === null) {
            throw new DomainException('Reviewer user was not found in current scope.');
        }

        $updated = $this->platformUserApprovalCaseRepository->updateCase($id, [
            'status' => $normalizedDecision,
            'decision_reason' => $normalizedReason,
            'reviewer_user_id' => $actorId,
            'decided_at' => now(),
        ]);
        if ($updated === null) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->platformUserApprovalCaseRepository->writeAuditLog(
                approvalCaseId: $id,
                action: 'platform.user-approval-case.decided',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'decision' => $normalizedDecision,
                ],
            );
        }

        return $this->platformUserApprovalCaseRepository->findCaseById($id) ?? $updated;
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
            'reviewer_user_id',
            'decided_at',
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

