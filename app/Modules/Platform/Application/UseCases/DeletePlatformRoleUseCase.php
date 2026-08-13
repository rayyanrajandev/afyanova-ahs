<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Exceptions\PlatformRoleProtectedException;
use App\Modules\Platform\Domain\Repositories\PlatformRbacRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class DeletePlatformRoleUseCase
{
    public function __construct(
        private readonly PlatformRbacRepositoryInterface $platformRbacRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, ?int $actorId = null): bool
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $role = $this->platformRbacRepository->findRoleById($id);
        if (! $role) {
            return false;
        }

        if (($role['is_system'] ?? false) === true) {
            throw new PlatformRoleProtectedException('System role cannot be deleted.');
        }

        $deleted = $this->platformRbacRepository->deleteRole($id);
        if (! $deleted) {
            return false;
        }

        $this->platformRbacRepository->writeAuditLog(
            tenantId: $role['tenant_id'] ?? null,
            facilityId: $role['facility_id'] ?? null,
            actorId: $actorId,
            action: 'platform-rbac.role.deleted',
            targetType: 'role',
            targetId: $id,
            changes: [
                'before' => $role,
            ],
        );

        return true;
    }
}

