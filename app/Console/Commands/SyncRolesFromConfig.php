<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncRolesFromConfig extends Command
{
    protected $signature = 'roles:sync';

    protected $description = 'Create or update all roles from config/roles.php for every facility';

    public function handle(): int
    {
        $roles = config('roles');

        if (empty($roles)) {
            $this->error('No roles defined in config/roles.php.');

            return self::FAILURE;
        }

        $platformRoles = array_filter($roles, fn (array $def): bool =>
            ($def['scope_type'] ?? null) === 'cross_facility');

        $facilityRoles = array_filter($roles, fn (array $def): bool =>
            ($def['scope_type'] ?? null) !== 'cross_facility');

        $tenants = DB::table('tenants')->get();
        $tenantId = $tenants->first()?->id;

        // Platform roles — created once, no facility_id
        if (! empty($platformRoles)) {
            foreach ($platformRoles as $roleKey => $roleDef) {
                $this->syncPlatformRole($roleDef, $tenantId);
            }
            $this->line('Synced platform roles.');
        }

        // Facility roles — created per facility
        $facilities = DB::table('facilities')->get();

        if ($facilities->isEmpty()) {
            $this->warn('No facilities found. Skipping facility roles.');

            return self::SUCCESS;
        }

        foreach ($facilities as $facility) {
            foreach ($facilityRoles as $roleKey => $roleDef) {
                $this->syncFacilityRole($roleDef, $roleKey, $facility, $tenantId);
            }
            $this->line('Synced roles for facility: '.($facility->name ?? $facility->id));
        }

        $this->info('All roles synced from config/roles.php.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $roleDef
     */
    private function syncPlatformRole(array $roleDef, ?string $tenantId): void
    {
        $code = $roleDef['code'];

        $perms = $roleDef['permissions'] ?? [];
        unset($roleDef['permissions']);

        $uniqueKeys = [
            'tenant_id' => $tenantId,
            'facility_id' => null,
            'code' => $code,
        ];

        $this->upsertRole($uniqueKeys, $roleDef, null, $perms);
    }

    /**
     * @param  array<string, mixed>  $roleDef
     */
    private function syncFacilityRole(array $roleDef, string $roleKey, object $facility, ?string $tenantId): void
    {
        $code = $roleDef['code'];

        $perms = $roleDef['permissions'] ?? [];
        unset($roleDef['permissions']);

        $departmentId = null;
        if (($roleDef['scope_type'] ?? null) === 'own_department') {
            $departmentId = $this->resolveDepartmentId($facility->id, $roleKey);
        }

        $uniqueKeys = [
            'tenant_id' => $tenantId,
            'facility_id' => $facility->id,
            'code' => $code,
        ];

        $this->upsertRole($uniqueKeys, $roleDef, $departmentId, $perms);
    }

    /**
     * @param  array<string, mixed>  $uniqueKeys
     * @param  array<string, mixed>  $roleDef
     * @param  string|null  $departmentId
     * @param  array<int, string>  $perms
     */
    private function upsertRole(array $uniqueKeys, array $roleDef, ?string $departmentId, array $perms): void
    {
        $query = DB::table('roles');
        foreach ($uniqueKeys as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $value);
            }
        }
        $existing = $query->first();

        $attributes = [
            'department_id' => $departmentId,
            'name' => $roleDef['name'],
            'description' => $roleDef['description'] ?? null,
            'access_level' => $roleDef['access_level'] ?? null,
            'scope_type' => $roleDef['scope_type'] ?? null,
            'is_system' => $roleDef['is_system'] ?? false,
            'status' => 'active',
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('roles')->where('id', $existing->id)->update($attributes);
            $roleId = $existing->id;
        } else {
            $roleId = Str::orderedUuid()->toString();
            DB::table('roles')->insert(array_merge($uniqueKeys, $attributes, [
                'id' => $roleId,
                'created_at' => now(),
            ]));
        }

        if (empty($perms)) {
            return;
        }

        $permIds = DB::table('permissions')
            ->whereIn('name', $perms)
            ->pluck('id');

        DB::table('permission_role')
            ->where('role_id', $roleId)
            ->whereNotIn('permission_id', $permIds)
            ->delete();

        foreach ($permIds as $permId) {
            DB::table('permission_role')->updateOrInsert(
                ['permission_id' => $permId, 'role_id' => $roleId],
            );
        }
    }

    private function resolveDepartmentId(string $facilityId, string $roleKey): ?string
    {
        $departmentMap = [
            'lab-technologist' => 'Laboratory',
            'lab-supervisor' => 'Laboratory',
            'lab-manager' => 'Laboratory',
            'dispenser' => 'Pharmacy',
            'pharmacist' => 'Pharmacy',
            'radiographer' => 'Radiology',
            'radiographer-senior' => 'Radiology',
            'storekeeper' => 'Store',
            'senior-storekeeper' => 'Store',
            'procurement-officer' => 'Store',
            'theatre-nurse' => 'Theatre',
            'theatre-nurse-in-charge' => 'Theatre',
            'theatre-manager' => 'Theatre',
        ];

        $deptName = $departmentMap[$roleKey] ?? null;
        if ($deptName === null) {
            return null;
        }

        return DB::table('departments')
            ->where('facility_id', $facilityId)
            ->where('name', 'LIKE', "%{$deptName}%")
            ->value('id');
    }
}
