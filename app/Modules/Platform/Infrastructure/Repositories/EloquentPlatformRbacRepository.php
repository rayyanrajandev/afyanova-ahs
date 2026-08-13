<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Models\Permission;
use App\Models\User;
use App\Modules\Platform\Domain\Repositories\PlatformRbacRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Models\PlatformRbacAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentPlatformRbacRepository implements PlatformRbacRepositoryInterface
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
    ) {}

    public function searchPermissions(
        ?string $query,
        int $page,
        int $perPage
    ): array {
        $paginator = Permission::query()
            ->when($query, fn (Builder $builder, string $value) => $builder->where('name', 'like', '%'.$value.'%'))
            ->orderBy('name')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult($paginator, static fn (Permission $permission): array => [
            'id' => $permission->id,
            'name' => $permission->name,
            'created_at' => $permission->created_at,
            'updated_at' => $permission->updated_at,
        ]);
    }

    public function searchRoles(
        ?string $query,
        ?string $status,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
        ?string $facilityId = null,
        ?bool $assignableOnly = false,
    ): array {
        $sortBy = in_array($sortBy, ['code', 'name', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'name';

        $queryBuilder = RoleModel::query()->withCount(['users', 'permissions']);
        $this->applyRoleScopeIfEnabled($queryBuilder);

        $effectiveFacilityId = $facilityId ?? $this->platformScopeContext->facilityId();

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('code', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($effectiveFacilityId, fn (Builder $builder, string $value) => $builder->where('facility_id', $value))
            ->when($assignableOnly, function (Builder $builder): void {
                $builder->where(function (Builder $q): void {
                    $q->where('code', 'NOT LIKE', 'PLATFORM.%')
                      ->where('code', 'NOT LIKE', '%SUPER.ADMIN%')
                      ->where('code', '!=', 'ADMIN.FACILITY')
                      ->where(function (Builder $prefixes): void {
                          $prefixes->where('code', 'LIKE', 'ADMIN.%')
                              ->orWhere('code', 'LIKE', 'CLINICAL.%')
                              ->orWhere('code', 'LIKE', 'FINANCE.%')
                              ->orWhere('code', 'LIKE', 'LAB.%')
                              ->orWhere('code', 'LIKE', 'RADIOLOGY.%')
                              ->orWhere('code', 'LIKE', 'PHARMACY.%')
                              ->orWhere('code', 'LIKE', 'THEATRE.%')
                              ->orWhere('code', 'LIKE', 'INVENTORY.%')
                              ->orWhere('code', 'LIKE', 'HOSPITAL.%');
                      });
                });
            })
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toPagedResult($paginator, fn (RoleModel $role): array => $this->toRolePayload($role));
    }

    public function findRoleById(string $id): ?array
    {
        $query = RoleModel::query()
            ->with(['permissions:id,name'])
            ->withCount(['users', 'permissions']);
        $this->applyRoleScopeIfEnabled($query);

        $role = $query->find($id);

        return $role ? $this->toRolePayload($role) : null;
    }

    public function createRole(array $attributes): array
    {
        $role = new RoleModel();
        $role->fill($attributes);
        $role->save();
        $role->load(['permissions:id,name'])->loadCount(['users', 'permissions']);

        return $this->toRolePayload($role);
    }

    public function updateRole(string $id, array $attributes): ?array
    {
        $query = RoleModel::query();
        $this->applyRoleScopeIfEnabled($query);

        $role = $query->find($id);
        if (! $role) {
            return null;
        }

        $role->fill($attributes);
        $role->save();
        $role->load(['permissions:id,name'])->loadCount(['users', 'permissions']);

        return $this->toRolePayload($role);
    }

    public function deleteRole(string $id): bool
    {
        $query = RoleModel::query();
        $this->applyRoleScopeIfEnabled($query);

        $role = $query->find($id);
        if (! $role) {
            return false;
        }

        return (bool) $role->delete();
    }

    public function existsRoleCodeInScope(
        string $code,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool {
        $query = RoleModel::query()
            ->whereRaw('LOWER(code) = ?', [strtolower(trim($code))]);

        if ($tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $tenantId);
        }

        if ($facilityId === null) {
            $query->whereNull('facility_id');
        } else {
            $query->where('facility_id', $facilityId);
        }

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function syncRolePermissions(string $roleId, array $permissionNames): ?array
    {
        $query = RoleModel::query();
        $this->applyRoleScopeIfEnabled($query);

        $role = $query->find($roleId);
        if (! $role) {
            return null;
        }

        $resolvedPermissionIds = Permission::query()
            ->whereIn('name', $permissionNames)
            ->pluck('id')
            ->all();

        $role->permissions()->sync($resolvedPermissionIds);
        $role->load(['permissions:id,name'])->loadCount(['users', 'permissions']);

        return $this->toRolePayload($role);
    }

    public function resolveExistingPermissionNames(array $permissionNames): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            $permissionNames,
        ))));

        if ($normalized === []) {
            return [];
        }

        return Permission::query()
            ->whereIn('name', $normalized)
            ->pluck('name')
            ->all();
    }

    public function resolveExistingRoleIdsInScope(array $roleIds): array
    {
        $normalizedRoleIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            $roleIds,
        ))));

        if ($normalizedRoleIds === []) {
            return [];
        }

        $query = RoleModel::query()->whereIn('id', $normalizedRoleIds);
        $this->applyRoleScopeIfEnabled($query);

        return $query
            ->pluck('id')
            ->all();
    }

    public function syncUserRoles(int $userId, array $roleIds): ?array
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return null;
        }

        $scopedAssignedRoleIdsQuery = RoleModel::query()
            ->select('roles.id')
            ->join('role_user', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $userId);
        $this->applyRoleScopeIfEnabled($scopedAssignedRoleIdsQuery);

        $scopedAssignedRoleIds = $scopedAssignedRoleIdsQuery
            ->pluck('roles.id')
            ->all();

        if ($scopedAssignedRoleIds !== []) {
            $user->roles()->detach($scopedAssignedRoleIds);
        }

        // Scope the roles being attached the same way every read in this
        // class already scopes roles being listed — otherwise, once scoping
        // is enabled, a caller could attach an out-of-scope role id (a
        // different tenant's custom role, a role they can't otherwise see)
        // by passing it directly in $roleIds with no validation.
        $rolesQuery = RoleModel::query()->whereIn('id', $roleIds);
        $this->applyRoleScopeIfEnabled($rolesQuery);
        $roles = $rolesQuery->orderBy('name')->get();
        $scopedRoleIds = $roles->pluck('id')->all();

        if ($scopedRoleIds !== []) {
            $user->roles()->syncWithoutDetaching($scopedRoleIds);
        }

        $user->unsetRelation('roles');

        return [
            'user_id' => $userId,
            'role_ids' => $roles->pluck('id')->all(),
            'roles' => $roles->map(fn (RoleModel $role): array => $this->toRolePayload($role))->all(),
        ];
    }

    public function writeAuditLog(
        ?string $tenantId,
        ?string $facilityId,
        ?int $actorId,
        string $action,
        ?string $targetType,
        ?string $targetId,
        array $changes = [],
        array $metadata = []
    ): void {
        PlatformRbacAuditLogModel::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function listAuditLogs(
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $targetType,
        ?string $targetId,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = PlatformRbacAuditLogModel::query();
        $this->applyAuditLogScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $value) => $builder->whereRaw('LOWER(action) LIKE ?', ['%'.strtolower($value).'%']))
            ->when($action, fn (Builder $builder, string $value) => $builder->where('action', $value))
            ->when($targetType, fn (Builder $builder, string $value) => $builder->where('target_type', $value))
            ->when($targetId, fn (Builder $builder, string $value) => $builder->where('target_id', $value))
            ->when($actorType === 'system', fn (Builder $builder) => $builder->whereNull('actor_id'))
            ->when($actorType === 'user', fn (Builder $builder) => $builder->whereNotNull('actor_id'))
            ->when($actorId !== null, fn (Builder $builder) => $builder->where('actor_id', $actorId))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('created_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('created_at', '<=', $value))
            ->orderByDesc('created_at');

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toPagedResult($paginator, static fn (PlatformRbacAuditLogModel $log): array => $log->toArray());
    }

    /**
     * @param  callable  $mapper
     */
    private function toPagedResult(LengthAwarePaginator $paginator, callable $mapper): array
    {
        return [
            'data' => array_map($mapper, $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }

    private function toRolePayload(RoleModel $role): array
    {
        $payload = $role->toArray();

        if ($role->relationLoaded('permissions')) {
            $payload['permission_names'] = $role->permissions
                ->pluck('name')
                ->values()
                ->all();
        } else {
            $payload['permission_names'] = [];
        }

        return $payload;
    }

    private function applyRoleScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function applyAuditLogScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->platformScopeContext->hasFacility()
            || $this->platformScopeContext->hasTenant()
            || $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
