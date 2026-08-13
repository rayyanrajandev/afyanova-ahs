<?php

namespace App\Modules\Staff\Infrastructure\Services;

use App\Models\User;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Staff\Domain\Services\UserLookupServiceInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UserLookupService implements UserLookupServiceInterface
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function userExists(int $userId): bool
    {
        return User::query()->whereKey($userId)->exists();
    }

    public function searchEligibleUsers(?string $query, int $limit = 10): array
    {
        $normalizedQuery = trim((string) $query);
        $queryBuilder = $this->eligibleUsersQuery();

        if ($normalizedQuery !== '') {
            $loweredQuery = mb_strtolower($normalizedQuery);

            $queryBuilder->where(function (Builder $builder) use ($loweredQuery): void {
                $builder
                    ->whereRaw('LOWER(users.name) LIKE ?', ['%'.$loweredQuery.'%'])
                    ->orWhereRaw('LOWER(users.email) LIKE ?', ['%'.$loweredQuery.'%']);
            });
        }

        $users = $queryBuilder
            ->limit(max(1, min($limit, 20)))
            ->get();

        return $this->decorateUsers($users->all());
    }

    public function findEligibleUserById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $user = $this->eligibleUsersQuery()
            ->whereKey($userId)
            ->first();

        if (! $user instanceof User) {
            return null;
        }

        $decorated = $this->decorateUsers([$user]);

        return $decorated[0] ?? null;
    }

    /**
     * @return Builder<User>
     */
    private function eligibleUsersQuery(): Builder
    {
        $query = User::query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.email_verified_at',
                'users.status',
            ])
            ->leftJoin('staff_profiles', 'staff_profiles.user_id', '=', 'users.id')
            ->whereNull('staff_profiles.id')
            ->where('users.status', 'active')
            ->orderBy('users.name')
            ->orderBy('users.email');

        $this->applyUserScopeIfEnabled($query);

        return $query;
    }

    /**
     * @param  array<int, User>  $users
     * @return array<int, array<string, mixed>>
     */
    private function decorateUsers(array $users): array
    {
        $userIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $user): int => $user instanceof User ? (int) $user->id : 0,
            $users,
        ))));

        if ($userIds === []) {
            return [];
        }

        $rolesByUser = [];
        $roleRows = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereIn('role_user.user_id', $userIds)
            ->orderBy('roles.name')
            ->get([
                'role_user.user_id',
                'roles.name as role_name',
            ]);

        foreach ($roleRows as $row) {
            $userId = (int) ($row->user_id ?? 0);
            $roleName = trim((string) ($row->role_name ?? ''));
            if ($userId <= 0 || $roleName === '') {
                continue;
            }

            $rolesByUser[$userId] ??= [];
            if (! in_array($roleName, $rolesByUser[$userId], true)) {
                $rolesByUser[$userId][] = $roleName;
            }
        }

        $facilitiesByUser = [];
        $facilityQuery = DB::table('facility_user')
            ->join('facilities', 'facilities.id', '=', 'facility_user.facility_id')
            ->whereIn('facility_user.user_id', $userIds)
            ->where('facility_user.is_active', true)
            ->orderByDesc('facility_user.is_primary')
            ->orderBy('facilities.name');

        if ($this->isPlatformScopingEnabled()) {
            $tenantId = $this->platformScopeContext->tenantId();
            if ($tenantId !== null) {
                $facilityQuery->where('facilities.tenant_id', $tenantId);
            } else {
                $facilityId = $this->platformScopeContext->facilityId();
                if ($facilityId !== null) {
                    $facilityQuery->where('facility_user.facility_id', $facilityId);
                }
            }
        }

        $facilityRows = $facilityQuery->get([
            'facility_user.user_id',
            'facilities.name as facility_name',
        ]);

        foreach ($facilityRows as $row) {
            $userId = (int) ($row->user_id ?? 0);
            $facilityName = trim((string) ($row->facility_name ?? ''));
            if ($userId <= 0 || $facilityName === '') {
                continue;
            }

            $facilitiesByUser[$userId] ??= [];
            if (! in_array($facilityName, $facilitiesByUser[$userId], true)) {
                $facilitiesByUser[$userId][] = $facilityName;
            }
        }

        $result = [];
        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $userId = (int) $user->id;
            $roleLabels = $rolesByUser[$userId] ?? [];
            $facilityLabels = $facilitiesByUser[$userId] ?? [];

            $result[] = [
                'id' => $userId,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'role_labels' => $roleLabels,
                'facility_labels' => $facilityLabels,
                'primary_facility_label' => $facilityLabels[0] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applyUserScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $tenantId = $this->platformScopeContext->tenantId();
        if ($tenantId !== null) {
            $query->where('users.tenant_id', $tenantId);

            return;
        }

        $facilityId = $this->platformScopeContext->facilityId();
        if ($facilityId !== null) {
            $query->whereExists(function ($queryBuilder) use ($facilityId): void {
                $queryBuilder
                    ->selectRaw('1')
                    ->from('facility_user')
                    ->whereColumn('facility_user.user_id', 'users.id')
                    ->where('facility_user.facility_id', $facilityId)
                    ->where('facility_user.is_active', true);
            });
        }
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
