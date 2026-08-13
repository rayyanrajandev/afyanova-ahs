<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutPlanStatus;
use DomainException;

class ExecuteMultiFacilityRolloutRollbackUseCase
{
    public function __construct(
        private readonly MultiFacilityRolloutRepositoryInterface $rolloutRepository,
        private readonly MultiFacilityRolloutAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $rolloutPlanId,
        string $reason,
        string $approvalCaseReference,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $plan = $this->rolloutRepository->findPlanById($rolloutPlanId);
        if ($plan === null) {
            return null;
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new DomainException('Rollback reason must be at least 10 characters.');
        }

        $approvalCaseReference = trim($approvalCaseReference);
        if ($approvalCaseReference === '') {
            throw new DomainException('Approval case reference is required.');
        }

        $existingMetadata = is_array($plan['metadata'] ?? null) ? $plan['metadata'] : [];

        $updated = $this->rolloutRepository->updatePlan($rolloutPlanId, [
            'status' => MultiFacilityRolloutPlanStatus::ROLLED_BACK->value,
            'rollback_required' => true,
            'rollback_reason' => $reason,
            'metadata' => array_merge($existingMetadata, [
                'rollbackApprovalCaseReference' => $approvalCaseReference,
            ]),
        ]);

        if ($updated === null) {
            return null;
        }

        $this->auditLogRepository->write(
            rolloutPlanId: $rolloutPlanId,
            action: 'platform.multi-facility-rollout.rollback.executed',
            actorId: $actorId,
            changes: [
                'status' => [
                    'before' => $plan['status'] ?? null,
                    'after' => MultiFacilityRolloutPlanStatus::ROLLED_BACK->value,
                ],
                'rollback_reason' => [
                    'before' => $plan['rollback_reason'] ?? null,
                    'after' => $reason,
                ],
            ],
            metadata: [
                'approvalCaseReference' => $approvalCaseReference,
            ],
        );

        return $this->rolloutRepository->findPlanById($rolloutPlanId);
    }
}
