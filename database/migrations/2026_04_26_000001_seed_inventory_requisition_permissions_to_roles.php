<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $rolePermissions = $this->rolePermissions();
        $permissionNames = array_values(array_unique(array_merge(...array_values($rolePermissions))));
        $now = now();

        foreach ($permissionNames as $permissionName) {
            $exists = DB::table('permissions')
                ->where('name', $permissionName)
                ->exists();

            if ($exists) {
                DB::table('permissions')
                    ->where('name', $permissionName)
                    ->update(['updated_at' => $now]);

                continue;
            }

            DB::table('permissions')->insert([
                'name' => $permissionName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIdsByName = DB::table('permissions')
            ->whereIn('name', $permissionNames)
            ->pluck('id', 'name');

        $rolesByCode = DB::table('roles')
            ->whereIn('code', array_keys($rolePermissions))
            ->pluck('id', 'code');

        foreach ($rolePermissions as $roleCode => $permissions) {
            $roleId = $rolesByCode[$roleCode] ?? null;

            if ($roleId === null) {
                continue;
            }

            foreach ($permissions as $permissionName) {
                $permissionId = $permissionIdsByName[$permissionName] ?? null;

                if ($permissionId === null) {
                    continue;
                }

                $attached = DB::table('permission_role')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if ($attached) {
                    continue;
                }

                DB::table('permission_role')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $rolePermissions = $this->rolePermissions();
        $permissionNames = array_values(array_unique(array_merge(...array_values($rolePermissions))));
        $permissionIdsByName = DB::table('permissions')
            ->whereIn('name', $permissionNames)
            ->pluck('id', 'name');
        $rolesByCode = DB::table('roles')
            ->whereIn('code', array_keys($rolePermissions))
            ->pluck('id', 'code');

        foreach ($rolePermissions as $roleCode => $permissions) {
            $roleId = $rolesByCode[$roleCode] ?? null;

            if ($roleId === null) {
                continue;
            }

            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->whereIn(
                    'permission_id',
                    collect($permissions)
                        ->map(fn (string $permissionName) => $permissionIdsByName[$permissionName] ?? null)
                        ->filter()
                        ->values()
                        ->all(),
                )
                ->delete();
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rolePermissions(): array
    {
        $departmentRequester = [
            'inventory.procurement.read',
            'inventory.procurement.create-request',
        ];

        return [
            'HOSPITAL.FACILITY.ADMIN' => [
                'inventory.procurement.read',
                'inventory.procurement.manage-items',
                'inventory.procurement.create-movement',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'inventory.procurement.reconcile-stock',
                'inventory.procurement.view-audit-logs',
                'inventory.procurement.manage-suppliers',
                'inventory.procurement.manage-warehouses',
            ],
            'HOSPITAL.DEPARTMENT.HEAD' => $departmentRequester,
            'HOSPITAL.INVENTORY.STOREKEEPER' => [
                'inventory.procurement.read',
                'inventory.procurement.create-movement',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'inventory.procurement.reconcile-stock',
                'inventory.procurement.view-audit-logs',
            ],
            'HOSPITAL.CLINICAL.USER' => $departmentRequester,
            'HOSPITAL.CLINICIAN.ORDERING' => $departmentRequester,
            'HOSPITAL.NURSING.USER' => $departmentRequester,
            'HOSPITAL.EMERGENCY.USER' => $departmentRequester,
            'HOSPITAL.LABORATORY.USER' => $departmentRequester,
            'HOSPITAL.PHARMACY.USER' => $departmentRequester,
            'HOSPITAL.RADIOLOGY.USER' => $departmentRequester,
            'HOSPITAL.THEATRE.USER' => $departmentRequester,
        ];
    }
};
