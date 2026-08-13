<?php

namespace App\Modules\Platform\Application\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class FacilityAdminEligibilityPolicy
{
    private const FACILITY_ADMIN_ROLE_CODES = [
        'ADMIN.FACILITY',
    ];

    /**
     * @return array<int, User>
     */
    public function searchCandidates(string $query, int $limit = 8, ?string $tenantId = null, bool $unassignedOnly = false): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $like = '%'.mb_strtolower($query).'%';
        $tenantId = is_string($tenantId) && trim($tenantId) !== '' ? trim($tenantId) : null;

        return $this->eligibleUserQuery()
            ->when($unassignedOnly, function (Builder $builder): void {
                $builder->whereNull('users.tenant_id');
            })
            ->when(! $unassignedOnly && $tenantId !== null, function (Builder $builder) use ($tenantId): void {
                $builder->where(function (Builder $scope) use ($tenantId): void {
                    $scope
                        ->whereNull('users.tenant_id')
                        ->orWhere('users.tenant_id', $tenantId);
                });
            })
            ->where(function (Builder $builder) use ($like): void {
                $builder
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
            })
            ->orderBy('name')
            ->orderBy('email')
            ->limit(max(1, min($limit, 20)))
            ->get()
            ->all();
    }

    public function findEligibleUser(int $userId): ?User
    {
        return $this->eligibleUserQuery()->find($userId);
    }

    /**
     * @return array<int, string>
     */
    public function eligibleRoleCodes(): array
    {
        return self::FACILITY_ADMIN_ROLE_CODES;
    }

    private function eligibleUserQuery(): Builder
    {
        return User::query()
            ->with(['roles' => function ($query): void {
                $query
                    ->select('roles.id', 'roles.code', 'roles.name', 'roles.status')
                    ->whereIn('roles.code', self::FACILITY_ADMIN_ROLE_CODES)
                    ->where('roles.status', 'active');
            }])
            ->where('users.status', 'active')
            ->whereHas('roles', function (Builder $query): void {
                $query
                    ->whereIn('roles.code', self::FACILITY_ADMIN_ROLE_CODES)
                    ->where('roles.status', 'active');
            });
    }
}
