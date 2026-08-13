<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutAcceptanceStatus;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutPlanStatus;
use DomainException;

class UpdateMultiFacilityRolloutAcceptanceUseCase
{
    public function __construct(
        private readonly MultiFacilityRolloutRepositoryInterface $rolloutRepository,
        private readonly MultiFacilityRolloutAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $rolloutPlanId,
        string $acceptanceStatus,
        ?string $trainingCompletedAt,
        ?string $acceptanceCaseReference,
        ?int $actorId = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $plan = $this->rolloutRepository->findPlanById($rolloutPlanId);
        if ($plan === null) {
            return null;
        }

        $acceptanceStatus = strtolower(trim($acceptanceStatus));
        if (! in_array($acceptanceStatus, MultiFacilityRolloutAcceptanceStatus::values(), true)) {
            throw new DomainException('Invalid acceptance status.');
        }

        $trainingCompletedAt = $this->nullableTrimmedValue($trainingCompletedAt);
        $acceptanceCaseReference = $this->nullableTrimmedValue($acceptanceCaseReference);

        if ($acceptanceStatus === MultiFacilityRolloutAcceptanceStatus::ACCEPTED->value && $trainingCompletedAt === null) {
            throw new DomainException('trainingCompletedAt is required when acceptance status is accepted.');
        }

        if (in_array($acceptanceStatus, [
            MultiFacilityRolloutAcceptanceStatus::ACCEPTED->value,
            MultiFacilityRolloutAcceptanceStatus::REJECTED->value,
        ], true) && $acceptanceCaseReference === null) {
            throw new DomainException('acceptanceCaseReference is required when acceptance status is accepted or rejected.');
        }

        $this->rolloutRepository->upsertAcceptance($rolloutPlanId, [
            'training_completed_at' => $trainingCompletedAt,
            'acceptance_status' => $acceptanceStatus,
            'accepted_by_user_id' => $acceptanceStatus === MultiFacilityRolloutAcceptanceStatus::PENDING->value ? null : $actorId,
            'acceptance_case_reference' => $acceptanceCaseReference,
            'accepted_at' => $acceptanceStatus === MultiFacilityRolloutAcceptanceStatus::PENDING->value ? null : now(),
        ]);

        if ($acceptanceStatus === MultiFacilityRolloutAcceptanceStatus::ACCEPTED->value
            && in_array((string) ($plan['status'] ?? ''), [
                MultiFacilityRolloutPlanStatus::READY->value,
                MultiFacilityRolloutPlanStatus::ACTIVE->value,
            ], true)) {
            $this->rolloutRepository->updatePlan($rolloutPlanId, [
                'status' => MultiFacilityRolloutPlanStatus::COMPLETED->value,
                'actual_go_live_at' => $plan['actual_go_live_at'] ?? now(),
            ]);
        }

        $this->auditLogRepository->write(
            rolloutPlanId: $rolloutPlanId,
            action: 'platform.multi-facility-rollout.acceptance.updated',
            actorId: $actorId,
            metadata: [
                'acceptanceStatus' => $acceptanceStatus,
                'acceptanceCaseReference' => $acceptanceCaseReference,
            ],
        );

        return $this->rolloutRepository->findPlanById($rolloutPlanId);
    }

    private function nullableTrimmedValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
