<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'patients.create';

    /**
     * @var array<int, string>
     */
    private array $roleCodes = [
        'HOSPITAL.FACILITY.ADMIN',
        'HOSPITAL.REGISTRATION.CLERK',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => self::PERMISSION,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('permissions')
                ->where('id', $permissionId)
                ->update(['updated_at' => $now]);
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('code', $this->roleCodes)
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

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
            $roleIds = DB::table('roles')
                ->whereIn('code', $this->roleCodes)
                ->pluck('id');

            DB::table('permission_role')
                ->where('permission_id', $permissionId)
                ->whereIn('role_id', $roleIds)
                ->delete();
        }
    }
};
