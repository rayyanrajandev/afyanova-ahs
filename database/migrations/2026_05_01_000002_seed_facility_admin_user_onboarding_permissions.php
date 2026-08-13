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
        'platform.users.read',
        'platform.users.create',
        'platform.users.update',
        'platform.users.update-status',
        'platform.users.reset-password',
        'platform.users.view-audit-logs',
        'platform.rbac.read',
        'platform.rbac.manage-user-roles',
    ];

    /**
     * @var array<int, string>
     */
    private array $roleCodes = [
        'HOSPITAL.FACILITY.ADMIN',
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

        $roleIds = DB::table('roles')
            ->whereIn('code', $this->roleCodes)
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        foreach ($roleIds as $roleId) {
            foreach ($permissionIdsByName as $permissionId) {
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
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $this->permissions)
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->whereIn('code', $this->roleCodes)
            ->pluck('id');

        if ($permissionIds->isEmpty() || $roleIds->isEmpty()) {
            return;
        }

        DB::table('permission_role')
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('role_id', $roleIds)
            ->delete();
    }
};
