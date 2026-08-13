<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCarePlanAuditLogRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCarePlanRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInpatientWardCarePlanUseCase
{
    public function __construct(
        private readonly InpatientWardCarePlanRepositoryInterface $carePlanRepository,
        private readonly InpatientWardCarePlanAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->carePlanRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updatePayload = $payload;
        $updatePayload['last_updated_by_user_id'] = $actorId;

        $updated = $this->carePlanRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            inpatientWardCarePlanId: $id,
            action: 'inpatient-ward-care-plan.updated',
            actorId: $actorId,
            changes: [
                'before' => $existing,
                'after' => $updated,
            ],
        );

        return $updated;
    }
}

