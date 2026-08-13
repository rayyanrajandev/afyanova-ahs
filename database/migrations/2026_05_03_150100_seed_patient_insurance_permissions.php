<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'patients.insurance.read' => [
            'HOSPITAL.REGISTRATION.CLERK',
            'HOSPITAL.BILLING.USER',
            'HOSPITAL.CLAIMS.USER',
            'HOSPITAL.FACILITY.ADMIN',
        ],
        'patients.insurance.manage' => [
            'HOSPITAL.REGISTRATION.CLERK',
            'HOSPITAL.BILLING.USER',
            'HOSPITAL.FACILITY.ADMIN',
        ],
        'patients.insurance.verify' => [
            'HOSPITAL.BILLING.USER',
            'HOSPITAL.CLAIMS.USER',
            'HOSPITAL.FACILITY.ADMIN',
        ],
        'patients.insurance.view-audit-logs' => [
            'HOSPITAL.BILLING.USER',
            'HOSPITAL.CLAIMS.USER',
            'HOSPITAL.FACILITY.ADMIN',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach (self::PERMISSIONS as $permissionName => $roleCodes) {
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
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('permission_role')) {
            return;
        }

        DB::table('permission_role')
            ->whereIn('permission_id', DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id'))
            ->delete();
    }
};
