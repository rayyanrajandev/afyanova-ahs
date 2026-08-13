<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Models\User;
use App\Modules\Platform\Domain\Repositories\PlatformUserAdminRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Models\PlatformUserAdminAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EloquentPlatformUserAdminRepository implements PlatformUserAdminRepositoryInterface
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
    ) {}

    public function searchUsers(
        ?string $query,
        ?string $status,
        ?string $verification,
        ?string $roleId,
        ?string $facilityId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['name', 'email', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'name';

        $queryBuilder = User::query()
            ->with(['roles:id,code,name']);

        $this->applyUserScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($facilityId, function (Builder $builder, string $value): void {
                $builder->whereExists(function ($queryBuilder) use ($value): void {
                    $queryBuilder
                        ->selectRaw('1')
                        ->from('facility_user')
                        ->whereColumn('facility_user.user_id', 'users.id')
                        ->where('facility_user.facility_id', $value);
                });
            })
            ->orderBy($sortBy, $sortDirection);

        $this->applyVerificationFilter($queryBuilder, $verification);
        $this->applyRoleFilter($queryBuilder, $roleId);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        $userIds = array_map(static fn (User $user): int => (int) $user->id, $paginator->items());
        $facilityAssignmentsByUser = $this->listFacilityAssignmentsByUserIds($userIds);

        return $this->toPagedResult(
            $paginator,
            fn (User $user): array => $this->toUserPayload($user, $facilityAssignmentsByUser[(int) $user->id] ?? []),
        );
    }

    public function findUserById(int $id): ?array
    {
        $query = User::query()->with(['roles:id,code,name']);
        $this->applyUserScopeIfEnabled($query);

        $user = $query->find($id);
        if (! $user) {
            return null;
        }

        $facilityAssignmentsByUser = $this->listFacilityAssignmentsByUserIds([(int) $user->id]);
        $payload = $this->toUserPayload($user, $facilityAssignmentsByUser[(int) $user->id] ?? []);
        $privilegedContext = $this->findPrivilegedUserContextInScope((int) $user->id);

        $payload['requires_approval_case_for_sensitive_changes'] = (bool) ($privilegedContext['is_privileged'] ?? false);
        $payload['privileged_target_user'] = $privilegedContext;

        return $payload;
    }

    public function findPrivilegedUserContextInScope(int $userId): ?array
    {
        $query = User::query()->with([
            'permissions:id,name',
            'roles:id,code,is_system',
            'roles.permissions:id,name',
        ]);
        $this->applyUserScopeIfEnabled($query);

        $user = $query->find($userId);
        if (! $user) {
            return null;
        }

        $permissionNames = [];
        foreach ($user->permissions as $permission) {
            $name = trim((string) ($permission->name ?? ''));
            if ($name === '') {
                continue;
            }

            $permissionNames[] = $name;
        }

        $roleCodes = [];
        $systemRoleCodes = [];
        foreach ($user->roles as $role) {
            $roleCode = trim((string) ($role->code ?? ''));
            if ($roleCode !== '') {
                $roleCodes[] = $roleCode;
                if ((bool) ($role->is_system ?? false)) {
                    $systemRoleCodes[] = $roleCode;
                }
            }

            foreach ($role->permissions as $permission) {
                $name = trim((string) ($permission->name ?? ''));
                if ($name === '') {
                    continue;
                }

                $permissionNames[] = $name;
            }
        }

        $permissionNames = array_values(array_unique($permissionNames));
        $roleCodes = array_values(array_unique($roleCodes));
        $systemRoleCodes = array_values(array_unique($systemRoleCodes));

        $configuredPermissionNames = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            (array) config('platform_user_admin.privileged_change_controls.permission_names', []),
        ))));
        $configuredPermissionPrefixes = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            (array) config('platform_user_admin.privileged_change_controls.permission_prefixes', []),
        ))));

        $matchedPermissionNames = [];
        foreach ($permissionNames as $permissionName) {
            if (in_array($permissionName, $configuredPermissionNames, true)) {
                $matchedPermissionNames[] = $permissionName;

                continue;
            }

            foreach ($configuredPermissionPrefixes as $prefix) {
                if (! str_starts_with($permissionName, $prefix)) {
                    continue;
                }

                $matchedPermissionNames[] = $permissionName;

                break;
            }
        }

        $matchedPermissionNames = array_values(array_unique($matchedPermissionNames));

        return [
            'user_id' => $userId,
            'is_privileged' => $matchedPermissionNames !== [],
            'matched_permission_names' => $matchedPermissionNames,
            'role_codes' => $roleCodes,
            'system_role_codes' => $systemRoleCodes,
        ];
    }

    public function statusCounts(?string $query, ?string $verification, ?string $roleId, ?string $facilityId): array
    {
        $queryBuilder = User::query();
        $this->applyUserScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($facilityId, function (Builder $builder, string $value): void {
                $builder->whereExists(function ($queryBuilder) use ($value): void {
                    $queryBuilder
                        ->selectRaw('1')
                        ->from('facility_user')
                        ->whereColumn('facility_user.user_id', 'users.id')
                        ->where('facility_user.facility_id', $value);
                });
            });

        $this->applyVerificationFilter($queryBuilder, $verification);
        $this->applyRoleFilter($queryBuilder, $roleId);

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'active' => 0,
            'inactive' => 0,
            'other' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($status, $counts) && $status !== 'other' && $status !== 'total') {
                $counts[$status] += $aggregate;
            } else {
                $counts['other'] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    public function createUser(array $attributes): array
    {
        $user = new User();
        $user->fill($attributes);
        $user->save();
        $user->load(['roles:id,code,name']);

        $facilityAssignmentsByUser = $this->listFacilityAssignmentsByUserIds([(int) $user->id]);

        return $this->toUserPayload($user, $facilityAssignmentsByUser[(int) $user->id] ?? []);
    }

    public function updateUser(int $id, array $attributes): ?array
    {
        $query = User::query()->with(['roles:id,code,name']);
        $this->applyUserScopeIfEnabled($query);

        $user = $query->find($id);
        if (! $user) {
            return null;
        }

        $user->fill($attributes);
        $user->save();
        $user->load(['roles:id,code,name']);

        $facilityAssignmentsByUser = $this->listFacilityAssignmentsByUserIds([(int) $user->id]);

        return $this->toUserPayload($user, $facilityAssignmentsByUser[(int) $user->id] ?? []);
    }

    public function emailExists(string $email, ?int $excludeUserId = null): bool
    {
        $query = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))]);

        if ($excludeUserId !== null) {
            $query->where('id', '!=', $excludeUserId);
        }

        return $query->exists();
    }

    public function resolveExistingFacilityIdsInScope(array $facilityIds): array
    {
        $normalizedFacilityIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            $facilityIds,
        ))));

        if ($normalizedFacilityIds === []) {
            return [];
        }

        $query = DB::table('facilities')
            ->whereIn('id', $normalizedFacilityIds);

        $this->applyFacilityScopeToFacilitiesQueryIfEnabled($query);

        return $query
            ->pluck('id')
            ->all();
    }

    public function listUserFacilityAssignmentsInScope(int $userId): array
    {
        $rows = DB::table('facility_user')
            ->join('facilities', 'facilities.id', '=', 'facility_user.facility_id')
            ->join('tenants', 'tenants.id', '=', 'facilities.tenant_id')
            ->where('facility_user.user_id', $userId);

        $this->applyFacilityScopeToFacilityAssignmentsQueryIfEnabled($rows);

        return $rows
            ->orderByDesc('facility_user.is_primary')
            ->orderBy('tenants.code')
            ->orderBy('facilities.code')
            ->get([
                'facility_user.user_id',
                'facility_user.facility_id',
                'facility_user.role',
                'facility_user.is_primary',
                'facility_user.is_active',
                'facilities.code as facility_code',
                'facilities.name as facility_name',
                'facilities.tenant_id',
                'tenants.code as tenant_code',
                'tenants.name as tenant_name',
            ])
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    public function syncUserFacilitiesInScope(int $userId, array $facilityAssignments): ?array
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return null;
        }

        // Deliberately tenant-only, not facility-scoped like applyUserScopeIfEnabled()
        // (used for read/list operations). This method's whole purpose is granting
        // a user access to a facility they may not have yet — restricting the
        // lookup to users who already have a facility_user row in the caller's
        // facility (as applyUserScopeIfEnabled() does) would make first-time
        // onboarding to that facility impossible. The two remaining ways this could
        // be misused are already closed elsewhere: SyncPlatformUserFacilitiesUseCase
        // validates every submitted facility id against the caller's own scope via
        // resolveExistingFacilityIdsInScope() before this method ever runs, and the
        // detach step below (via listScopedFacilityIdsForUser()) only ever touches
        // facility_user rows already within the caller's own scope. A facility scope
        // always carries a matching tenant (see ResolvePlatformAccessScopeUseCase),
        // so this single tenant check covers both the tenant-scoped and
        // facility-scoped caller cases.
        $scopeTenantId = $this->platformScopeContext->tenantId();
        if ($scopeTenantId !== null) {
            $existingTenantId = is_string($user->tenant_id) && $user->tenant_id !== '' ? $user->tenant_id : null;

            if ($existingTenantId !== null && $existingTenantId !== $scopeTenantId) {
                return null;
            }

            if ($existingTenantId === null) {
                $user->tenant_id = $scopeTenantId;
                $user->save();
            }
        }

        $existingScopedFacilityIds = $this->listScopedFacilityIdsForUser($userId);
        $submittedFacilityIds = array_values(array_unique(array_filter(array_map(
            static fn (array $assignment): string => (string) ($assignment['facility_id'] ?? ''),
            $facilityAssignments,
        ))));

        $facilityIdsToDetach = array_values(array_diff($existingScopedFacilityIds, $submittedFacilityIds));
        if ($facilityIdsToDetach !== []) {
            DB::table('facility_user')
                ->where('user_id', $userId)
                ->whereIn('facility_id', $facilityIdsToDetach)
                ->delete();
        }

        $now = now();
        foreach ($facilityAssignments as $assignment) {
            $facilityId = (string) ($assignment['facility_id'] ?? '');
            if ($facilityId === '') {
                continue;
            }

            $existing = DB::table('facility_user')
                ->where('facility_id', $facilityId)
                ->where('user_id', $userId)
                ->exists();

            $values = [
                'role' => $assignment['role'] ?? null,
                'is_primary' => (bool) ($assignment['is_primary'] ?? false),
                'is_active' => (bool) ($assignment['is_active'] ?? true),
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('facility_user')
                    ->where('facility_id', $facilityId)
                    ->where('user_id', $userId)
                    ->update($values);
            } else {
                DB::table('facility_user')->insert([
                    'facility_id' => $facilityId,
                    'user_id' => $userId,
                    'role' => $values['role'],
                    'is_primary' => $values['is_primary'],
                    'is_active' => $values['is_active'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $result = $this->findUserById($userId);
        if ($result !== null) {
            return $result;
        }

        $fresh = User::query()->with(['roles:id,code,name'])->find($userId);
        if (! $fresh) {
            return null;
        }

        $facilityAssignmentsByUser = $this->listFacilityAssignmentsByUserIds([(int) $fresh->id]);

        return $this->toUserPayload($fresh, $facilityAssignmentsByUser[(int) $fresh->id] ?? []);
    }

    public function writeAuditLog(
        ?string $tenantId,
        ?string $facilityId,
        ?int $actorId,
        ?int $targetUserId,
        string $action,
        array $changes = [],
        array $metadata = []
    ): void {
        PlatformUserAdminAuditLogModel::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'actor_id' => $actorId,
            'target_user_id' => $targetUserId,
            'action' => $action,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function listAuditLogs(
        int $targetUserId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = PlatformUserAdminAuditLogModel::query()
            ->where('target_user_id', $targetUserId);

        if ($this->isPlatformScopingEnabled()) {
            $this->platformScopeQueryApplier->apply($queryBuilder);
        }

        $queryBuilder
            ->when($query, fn (Builder $builder, string $value) => $builder->whereRaw('LOWER(action) LIKE ?', ['%'.strtolower($value).'%']))
            ->when($action, fn (Builder $builder, string $value) => $builder->where('action', $value))
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

        return $this->toPagedResult($paginator, static fn (PlatformUserAdminAuditLogModel $log): array => $log->toArray());
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function listFacilityAssignmentsByUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $query = DB::table('facility_user')
            ->join('facilities', 'facilities.id', '=', 'facility_user.facility_id')
            ->join('tenants', 'tenants.id', '=', 'facilities.tenant_id')
            ->whereIn('facility_user.user_id', $userIds);

        $this->applyFacilityScopeToFacilityAssignmentsQueryIfEnabled($query);

        $rows = $query
            ->orderByDesc('facility_user.is_primary')
            ->orderBy('tenants.code')
            ->orderBy('facilities.code')
            ->get([
                'facility_user.user_id',
                'facility_user.facility_id',
                'facility_user.role',
                'facility_user.is_primary',
                'facility_user.is_active',
                'facilities.code as facility_code',
                'facilities.name as facility_name',
                'facilities.tenant_id',
                'tenants.code as tenant_code',
                'tenants.name as tenant_name',
            ]);

        $grouped = [];
        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $grouped[$userId] ??= [];
            $grouped[$userId][] = (array) $row;
        }

        return $grouped;
    }

    /**
     * @return array<int, string>
     */
    private function listScopedFacilityIdsForUser(int $userId): array
    {
        $query = DB::table('facility_user')
            ->where('facility_user.user_id', $userId);

        $this->applyFacilityScopeToFacilityUserQueryIfEnabled($query);

        return $query
            ->pluck('facility_user.facility_id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $facilityAssignments
     * @return array<string, mixed>
     */
    private function toUserPayload(User $user, array $facilityAssignments): array
    {
        if (! $user->relationLoaded('roles')) {
            $user->load(['roles:id,code,name']);
        }

        /** @var array<int, RoleModel> $roles */
        $roles = $user->roles->all();
        $rolePayloads = array_map(static fn (RoleModel $role): array => [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
        ], $roles);

        $payload = $user->toArray();
        $payload['facility_assignments'] = $facilityAssignments;
        $payload['roles'] = $rolePayloads;
        $payload['role_ids'] = array_values(array_map(
            static fn (array $role): string => (string) ($role['id'] ?? ''),
            $rolePayloads,
        ));

        return $payload;
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

    private function applyUserScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $facilityId = $this->platformScopeContext->facilityId();
        if ($facilityId !== null) {
            $query->whereExists(function ($queryBuilder) use ($facilityId): void {
                $queryBuilder
                    ->selectRaw('1')
                    ->from('facility_user')
                    ->whereColumn('facility_user.user_id', 'users.id')
                    ->where('facility_user.facility_id', $facilityId);
            });

            return;
        }

        $tenantId = $this->platformScopeContext->tenantId();
        if ($tenantId !== null) {
            $query->where('users.tenant_id', $tenantId);
        }
    }

    private function applyVerificationFilter(Builder $query, ?string $verification): void
    {
        if ($verification === 'verified') {
            $query->whereNotNull('users.email_verified_at');

            return;
        }

        if ($verification === 'unverified') {
            $query->whereNull('users.email_verified_at');
        }
    }

    private function applyRoleFilter(Builder $query, ?string $roleId): void
    {
        if ($roleId === null || $roleId === '') {
            return;
        }

        $query->whereExists(function ($queryBuilder) use ($roleId): void {
            $queryBuilder
                ->selectRaw('1')
                ->from('role_user')
                ->whereColumn('role_user.user_id', 'users.id')
                ->where('role_user.role_id', $roleId);
        });
    }

    private function applyFacilityScopeToFacilitiesQueryIfEnabled($query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $facilityId = $this->platformScopeContext->facilityId();
        if ($facilityId !== null) {
            $query->where('facilities.id', $facilityId);

            return;
        }

        $tenantId = $this->platformScopeContext->tenantId();
        if ($tenantId !== null) {
            $query->where('facilities.tenant_id', $tenantId);
        }
    }

    private function applyFacilityScopeToFacilityAssignmentsQueryIfEnabled($query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $facilityId = $this->platformScopeContext->facilityId();
        if ($facilityId !== null) {
            $query->where('facility_user.facility_id', $facilityId);

            return;
        }

        $tenantId = $this->platformScopeContext->tenantId();
        if ($tenantId !== null) {
            $query->where('facilities.tenant_id', $tenantId);
        }
    }

    private function applyFacilityScopeToFacilityUserQueryIfEnabled($query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $facilityId = $this->platformScopeContext->facilityId();
        if ($facilityId !== null) {
            $query->where('facility_user.facility_id', $facilityId);

            return;
        }

        $tenantId = $this->platformScopeContext->tenantId();
        if ($tenantId !== null) {
            $query
                ->join('facilities', 'facilities.id', '=', 'facility_user.facility_id')
                ->where('facilities.tenant_id', $tenantId);
        }
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->platformScopeContext->hasFacility()
            || $this->platformScopeContext->hasTenant()
            || $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
