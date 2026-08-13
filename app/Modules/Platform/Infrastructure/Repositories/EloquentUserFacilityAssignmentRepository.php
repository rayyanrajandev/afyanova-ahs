<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\UserFacilityAssignmentRepositoryInterface;
use Illuminate\Support\Facades\DB;

class EloquentUserFacilityAssignmentRepository implements UserFacilityAssignmentRepositoryInterface
{
    /**
     * @var array<int, string>
     */
    private const PLATFORM_SUPER_ADMIN_ROLE_CODES = [
        'PLATFORM.SUPER.ADMIN',
        'SYSTEM.SUPER.ADMIN',
    ];

    public function listActiveFacilityScopesByUserId(int $userId): array
    {
        if ($this->hasUniversalSuperAdminAccess($userId)) {
            return $this->listAllActiveFacilityScopesForSuperAdmin($userId);
        }

        return DB::table('facility_user')
            ->join('facilities', 'facilities.id', '=', 'facility_user.facility_id')
            ->join('tenants', 'tenants.id', '=', 'facilities.tenant_id')
            ->where('facility_user.user_id', $userId)
            ->where('facility_user.is_active', true)
            ->where('facilities.status', 'active')
            ->where('tenants.status', 'active')
            ->orderByDesc('facility_user.is_primary')
            ->orderBy('tenants.code')
            ->orderBy('facilities.code')
            ->get([
                'facility_user.user_id',
                'facility_user.is_primary',
                'facility_user.role as assignment_role',
                'tenants.id as tenant_id',
                'tenants.code as tenant_code',
                'tenants.name as tenant_name',
                'tenants.country_code as tenant_country_code',
                'facilities.id as facility_id',
                'facilities.code as facility_code',
                'facilities.name as facility_name',
                'facilities.facility_type',
                'facilities.timezone as facility_timezone',
            ])
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    private function hasUniversalSuperAdminAccess(int $userId): bool
    {
        return $this->hasPlatformAdminFlag($userId)
            || $this->hasActiveSuperAdminAssignment($userId)
            || $this->hasActivePlatformSuperAdminRole($userId);
    }

    /**
     * users.is_platform_admin is the single source of truth for platform-admin
     * status (User::isPlatformSuperAdmin()) — checked here too so an account
     * bootstrapped via that flag alone (no legacy facility_user.role/role_user
     * row) still gets the full facility list instead of falling through to the
     * per-assignment query below.
     */
    private function hasPlatformAdminFlag(int $userId): bool
    {
        return DB::table('users')
            ->where('id', $userId)
            ->where('is_platform_admin', true)
            ->exists();
    }

    private function hasActiveSuperAdminAssignment(int $userId): bool
    {
        return DB::table('facility_user')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('role', 'super_admin')
            ->exists();
    }

    private function hasActivePlatformSuperAdminRole(int $userId): bool
    {
        return DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $userId)
            ->where('roles.status', 'active')
            ->whereIn('roles.code', self::PLATFORM_SUPER_ADMIN_ROLE_CODES)
            ->exists();
    }

    private function listAllActiveFacilityScopesForSuperAdmin(int $userId): array
    {
        $primaryFacilityId = DB::table('facility_user')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('role', 'super_admin')
            ->orderByDesc('is_primary')
            ->value('facility_id');

        $facilities = DB::table('facilities')
            ->join('tenants', 'tenants.id', '=', 'facilities.tenant_id')
            ->where('facilities.status', 'active')
            ->where('tenants.status', 'active')
            ->orderBy('tenants.code')
            ->orderBy('facilities.code')
            ->get([
                'tenants.id as tenant_id',
                'tenants.code as tenant_code',
                'tenants.name as tenant_name',
                'tenants.country_code as tenant_country_code',
                'facilities.id as facility_id',
                'facilities.code as facility_code',
                'facilities.name as facility_name',
                'facilities.facility_type',
                'facilities.timezone as facility_timezone',
            ])
            ->map(static fn ($row): array => [
                'user_id' => $userId,
                'is_primary' => (string) $row->facility_id === (string) $primaryFacilityId,
                'assignment_role' => 'super_admin',
                'tenant_id' => $row->tenant_id,
                'tenant_code' => $row->tenant_code,
                'tenant_name' => $row->tenant_name,
                'tenant_country_code' => $row->tenant_country_code,
                'facility_id' => $row->facility_id,
                'facility_code' => $row->facility_code,
                'facility_name' => $row->facility_name,
                'facility_type' => $row->facility_type,
                'facility_timezone' => $row->facility_timezone,
            ])
            ->all();

        usort($facilities, static function (array $left, array $right): int {
            $primarySort = ((int) ($right['is_primary'] ?? false)) <=> ((int) ($left['is_primary'] ?? false));
            if ($primarySort !== 0) {
                return $primarySort;
            }

            return strcmp(
                (string) ($left['tenant_code'] ?? '').(string) ($left['facility_code'] ?? ''),
                (string) ($right['tenant_code'] ?? '').(string) ($right['facility_code'] ?? ''),
            );
        });

        return $facilities;
    }
}
