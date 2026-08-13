<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Models\User;
use App\Modules\Platform\Application\Exceptions\UnknownPlatformRbacRoleException;
use App\Modules\Platform\Application\Support\PrivilegedPlatformUserChangePolicy;
use App\Modules\Platform\Domain\Repositories\PlatformRbacRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\PlatformUserAdminRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Support\Facades\DB;

class SyncPlatformUserRolesUseCase
{
    public function __construct(
        private readonly PlatformRbacRepositoryInterface $platformRbacRepository,
        private readonly PlatformUserAdminRepositoryInterface $platformUserAdminRepository,
        private readonly PrivilegedPlatformUserChangePolicy $privilegedPlatformUserChangePolicy,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * @param  array<int, string>  $roleIds
     */
    public function execute(
        int $userId,
        array $roleIds,
        ?string $approvalCaseReference = null,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $normalizedApprovalCaseReference = $this->privilegedPlatformUserChangePolicy->normalizeApprovalCaseReference(
            $approvalCaseReference,
        );
        $privilegedContext = $this->platformUserAdminRepository->findPrivilegedUserContextInScope($userId);
        $this->privilegedPlatformUserChangePolicy->assertApprovalCaseReferenceForTarget(
            privilegedContext: $privilegedContext,
            approvalCaseReference: $normalizedApprovalCaseReference,
        );

        $normalizedRoleIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            $roleIds,
        ))));

        $isSelfAssignment = $actorId !== null && $actorId === $userId;

        if (! $this->actorCanAssignAnyRole($actorId) || $isSelfAssignment) {
            $currentRoleIds = DB::table('role_user')
                ->where('user_id', $userId)
                ->pluck('role_id')
                ->map(static fn ($id): string => (string) $id)
                ->all();
            $newlyAddedRoleIds = array_values(array_diff($normalizedRoleIds, $currentRoleIds));
            $this->assertAssignableHospitalRoleIds($newlyAddedRoleIds);
        }

        $resolvedRoleIds = $this->platformRbacRepository->resolveExistingRoleIdsInScope($normalizedRoleIds);
        if (count($resolvedRoleIds) !== count($normalizedRoleIds)) {
            throw new UnknownPlatformRbacRoleException('One or more role ids are invalid for the current scope.');
        }

        $result = $this->platformRbacRepository->syncUserRoles($userId, $resolvedRoleIds);
        if (! $result) {
            return null;
        }

        $this->platformRbacRepository->writeAuditLog(
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
            actorId: $actorId,
            action: 'platform-rbac.user.roles.synced',
            targetType: 'user',
            targetId: (string) $userId,
            changes: [
                'role_ids' => [
                    'after' => $result['role_ids'] ?? [],
                ],
            ],
            metadata: array_merge(
                $this->privilegedPlatformUserChangePolicy->buildAuditMetadata(
                    privilegedContext: $privilegedContext,
                    approvalCaseReference: $normalizedApprovalCaseReference,
                ),
                $isSelfAssignment ? ['self_assignment' => true] : [],
            ),
        );

        return $result;
    }

    private function actorCanAssignAnyRole(?int $actorId): bool
    {
        if ($actorId === null) {
            return false;
        }

        $actor = User::query()->find($actorId);
        if (! $actor) {
            return false;
        }

        // Platform-only: a facility-scoped admin must not be able to assign ANY
        // role (including platform-wide ones) to any user — see
        // RBAC_Remediation_Plan.md Phase 2.
        return $actor->isPlatformSuperAdminAccess()
            || $actor->hasPermissionTo('platform.rbac.manage-roles')
            || (
                ! $this->platformScopeContext->hasFacility()
                && ! $this->actorHasActiveFacilityAssignment($actorId)
            );
    }

    private function actorHasActiveFacilityAssignment(int $actorId): bool
    {
        return DB::table('facility_user')
            ->where('user_id', $actorId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * @param  array<int, string>  $roleIds
     */
    private function assertAssignableHospitalRoleIds(array $roleIds): void
    {
        if ($roleIds === []) {
            return;
        }

        $roles = RoleModel::query()
            ->whereIn('id', $roleIds)
            ->get(['id', 'code']);

        if ($roles->count() !== count($roleIds)) {
            throw new UnknownPlatformRbacRoleException('One or more role ids are invalid for the current scope.');
        }

        foreach ($roles as $role) {
            $code = strtoupper(trim((string) ($role->code ?? '')));
            if (
                ! $this->isAllowedHospitalRoleCode($code)
            ) {
                throw new UnknownPlatformRbacRoleException(
                    'Facility administrators can assign hospital operational roles only.',
                );
            }
        }
    }

    private function isAllowedHospitalRoleCode(string $code): bool
    {
        if (str_starts_with($code, 'PLATFORM.') || str_contains($code, 'SUPER.ADMIN')) {
            return false;
        }

        if ($code === 'ADMIN.FACILITY') {
            return false;
        }

        $allowedPrefixes = [
            'ADMIN.', 'CLINICAL.', 'FINANCE.',
            'LAB.', 'RADIOLOGY.', 'PHARMACY.', 'THEATRE.',
            'INVENTORY.',
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
