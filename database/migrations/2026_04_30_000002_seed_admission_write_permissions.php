<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $permissions = [
        'admissions.create',
        'admissions.update',
        'admissions.update-status',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private array $rolePermissions = [
        'HOSPITAL.FACILITY.ADMIN' => [
            'admissions.create',
            'admissions.update',
            'admissions.update-status',
            'admissions.view-audit-logs',
        ],
        'HOSPITAL.REGISTRATION.CLERK' => [
            'admissions.create',
            'admissions.update',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $permissionIdsByName = [];

        foreach ($this->permissions as $permissionName) {
            $permissionId = DB::table('permissions')
                ->where('name', $permissionName)
                ->value('id');

            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $permissionName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('permissions')
                    ->where('id', $permissionId)
                    ->update(['updated_at' => $now]);
            }

            $permissionIdsByName[$permissionName] = $permissionId;
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $rolesByCode = DB::table('roles')
            ->whereIn('code', array_keys($this->rolePermissions))
            ->pluck('id', 'code');

        foreach ($this->rolePermissions as $roleCode => $permissionNames) {
            $roleId = $rolesByCode[$roleCode] ?? null;

            if ($roleId === null) {
                continue;
            }

            foreach ($permissionNames as $permissionName) {
                $permissionId = $permissionIdsByName[$permissionName] ?? DB::table('permissions')
                    ->where('name', $permissionName)
                    ->value('id');

                if ($permissionId === null) {
                    continue;
                }

                DB::table('permission_role')->updateOrInsert(
                    [
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ],
                    [
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $this->permissions)
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
            $roleIds = DB::table('roles')
                ->whereIn('code', array_keys($this->rolePermissions))
                ->pluck('id');

            DB::table('permission_role')
                ->whereIn('permission_id', $permissionIds)
                ->whereIn('role_id', $roleIds)
                ->delete();
        }
    }
};
