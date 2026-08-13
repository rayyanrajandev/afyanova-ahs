<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FacilityResourceAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FacilityResourceRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateFacilityResourceStatusUseCase
{
    public function __construct(
        private readonly FacilityResourceRepositoryInterface $facilityResourceRepository,
        private readonly FacilityResourceAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $resourceType, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->facilityResourceRepository->findById($id);
        if (! $existing || ($existing['resource_type'] ?? null) !== $resourceType) {
            return null;
        }

        $reasonRequired = $status === 'inactive';
        $reasonProvided = is_string($reason) && trim($reason) !== '';

        $updated = $this->facilityResourceRepository->update($id, [
            'status' => $status,
            'status_reason' => $reason,
        ]);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            facilityResourceId: $id,
            action: 'facility-resource.status.updated',
            actorId: $actorId,
            changes: [
                'status' => [
                    'before' => $existing['status'] ?? null,
                    'after' => $updated['status'] ?? null,
                ],
                'status_reason' => [
                    'before' => $existing['status_reason'] ?? null,
                    'after' => $updated['status_reason'] ?? null,
                ],
            ],
            metadata: [
                'resourceType' => $resourceType,
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => $reasonRequired,
                'reason_provided' => $reasonProvided,
            ],
        );

        return $updated;
    }
}
