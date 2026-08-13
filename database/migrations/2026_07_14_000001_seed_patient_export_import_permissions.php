<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $permissionRoleCodes = [
        'patients.export' => ['HOSPITAL.FACILITY.ADMIN', 'HOSPITAL.REGISTRATION.CLERK'],
        'patients.import' => ['HOSPITAL.FACILITY.ADMIN'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach ($this->permissionRoleCodes as $permission => $roleCodes) {
            $permissionId = DB::table('permissions')
                ->where('name', $permission)
                ->value('id');

            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $permission,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('permissions')
                    ->where('id', $permissionId)
                    ->update(['updated_at' => $now]);
            }

            if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
                continue;
            }

            $roleIds = DB::table('roles')
                ->whereIn('code', $roleCodes)
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ],
                    [
                        'created_at' => $now,
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

        foreach (array_keys($this->permissionRoleCodes) as $permission) {
            $permissionId = DB::table('permissions')
                ->where('name', $permission)
                ->value('id');

            if ($permissionId === null) {
                continue;
            }

            if (Schema::hasTable('permission_role')) {
                DB::table('permission_role')
                    ->where('permission_id', $permissionId)
                    ->delete();
            }
        }
    }
};
