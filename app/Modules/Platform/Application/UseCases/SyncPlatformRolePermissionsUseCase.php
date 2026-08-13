<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Models\User;
use App\Modules\Platform\Application\Exceptions\PlatformRoleProtectedException;
use App\Modules\Platform\Application\Exceptions\UnknownPlatformRbacPermissionException;
use App\Modules\Platform\Domain\Repositories\PlatformRbacRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class SyncPlatformRolePermissionsUseCase
{
    /**
     * Permissions that grant role-management or user-role-management power.
     * Non-super-admin actors cannot grant these to non-system roles.
     */
    private const ESCALATION_PERMISSIONS = [
        'platform.rbac.manage-roles',
        'platform.rbac.manage-user-roles',
    ];

    public function __construct(
        private readonly PlatformRbacRepositoryInterface $platformRbacRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * @param  array<int, string>  $permissionNames
     */
    public function execute(string $roleId, array $permissionNames, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existingRole = $this->platformRbacRepository->findRoleById($roleId);
        if (! $existingRole) {
            return null;
        }

        if (($existingRole['is_system'] ?? false) === true) {
            throw new PlatformRoleProtectedException('System role permissions cannot be modified.');
        }

        $normalizedPermissionNames = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            $permissionNames,
        ))));

        $resolvedPermissionNames = $this->platformRbacRepository->resolveExistingPermissionNames($normalizedPermissionNames);
        if (count($resolvedPermissionNames) !== count($normalizedPermissionNames)) {
            throw new UnknownPlatformRbacPermissionException('One or more permission names are invalid.');
        }

        if (! $this->actorIsSuperAdmin($actorId)) {
            $this->assertNoEscalationPermissions($resolvedPermissionNames);
        }

        $updatedRole = $this->platformRbacRepository->syncRolePermissions($roleId, $resolvedPermissionNames);
        if (! $updatedRole) {
            return null;
        }

        $this->platformRbacRepository->writeAuditLog(
            tenantId: $updatedRole['tenant_id'] ?? null,
            facilityId: $updatedRole['facility_id'] ?? null,
            actorId: $actorId,
            action: 'platform-rbac.role.permissions.synced',
            targetType: 'role',
            targetId: $roleId,
            changes: [
                'permission_names' => [
                    'before' => $existingRole['permission_names'] ?? [],
                    'after' => $updatedRole['permission_names'] ?? [],
                ],
            ],
        );

        return $updatedRole;
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    private function assertNoEscalationPermissions(array $permissionNames): void
    {
        $granted = array_intersect($permissionNames, self::ESCALATION_PERMISSIONS);
        if ($granted !== []) {
            throw new PlatformRoleProtectedException(
                'You cannot grant role-management or user-role-management permissions to a non-system role.',
            );
        }
    }

    private function actorIsSuperAdmin(?int $actorId): bool
    {
        if ($actorId === null) {
            return false;
        }

        $actor = User::query()->find($actorId);

        // Platform-only: a facility-scoped admin must not be able to grant an
        // existing role platform.rbac.manage-roles/manage-user-roles — see
        // RBAC_Remediation_Plan.md Phase 2.
        return $actor !== null && $actor->isPlatformSuperAdminAccess();
    }
}

