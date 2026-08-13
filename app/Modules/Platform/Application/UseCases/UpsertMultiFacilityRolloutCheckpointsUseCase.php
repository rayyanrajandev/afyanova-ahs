<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutCheckpointStatus;

class UpsertMultiFacilityRolloutCheckpointsUseCase
{
    public function __construct(
        private readonly MultiFacilityRolloutRepositoryInterface $rolloutRepository,
        private readonly MultiFacilityRolloutAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $checkpoints
     */
    public function execute(string $rolloutPlanId, array $checkpoints, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $plan = $this->rolloutRepository->findPlanById($rolloutPlanId);
        if ($plan === null) {
            return null;
        }

        foreach ($checkpoints as $checkpoint) {
            $checkpointCode = strtoupper(trim((string) ($checkpoint['checkpoint_code'] ?? '')));
            $status = strtolower(trim((string) ($checkpoint['status'] ?? '')));

            if (! in_array($status, MultiFacilityRolloutCheckpointStatus::values(), true)) {
                continue;
            }

            $isCompleted = in_array($status, [
                MultiFacilityRolloutCheckpointStatus::PASSED->value,
                MultiFacilityRolloutCheckpointStatus::FAILED->value,
            ], true);

            $this->rolloutRepository->upsertCheckpoint($rolloutPlanId, $checkpointCode, [
                'checkpoint_name' => trim((string) ($checkpoint['checkpoint_name'] ?? '')),
                'status' => $status,
                'decision_notes' => $this->nullableTrimmedValue($checkpoint['decision_notes'] ?? null),
                'completed_by_user_id' => $isCompleted ? $actorId : null,
                'completed_at' => $isCompleted ? now() : null,
            ]);
        }

        $this->auditLogRepository->write(
            rolloutPlanId: $rolloutPlanId,
            action: 'platform.multi-facility-rollout.checkpoints.upserted',
            actorId: $actorId,
            metadata: [
                'checkpointCount' => count($checkpoints),
            ],
        );

        return $this->rolloutRepository->findPlanById($rolloutPlanId);
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
