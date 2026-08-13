<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'service.requests.create' => [
            'HOSPITAL.REGISTRATION.CLERK',
            'HOSPITAL.NURSING.USER',
            'HOSPITAL.FACILITY.ADMIN',
        ],
        'service.requests.read' => [
            'HOSPITAL.REGISTRATION.CLERK',
            'HOSPITAL.LABORATORY.USER',
            'HOSPITAL.PHARMACY.USER',
            'HOSPITAL.RADIOLOGY.USER',
            'HOSPITAL.THEATRE.USER',
            'HOSPITAL.NURSING.USER',
            'HOSPITAL.FACILITY.ADMIN',
        ],
        'service.requests.update-status' => [
            'HOSPITAL.LABORATORY.USER',
            'HOSPITAL.PHARMACY.USER',
            'HOSPITAL.RADIOLOGY.USER',
            'HOSPITAL.THEATRE.USER',
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
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (array_keys(self::PERMISSIONS) as $permissionName) {
            $permissionId = DB::table('permissions')
                ->where('name', $permissionName)
                ->value('id');

            if ($permissionId === null) {
                continue;
            }

            if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
                $roleCodes = self::PERMISSIONS[$permissionName];
                $roleIds = DB::table('roles')
                    ->whereIn('code', $roleCodes)
                    ->pluck('id');

                DB::table('permission_role')
                    ->where('permission_id', $permissionId)
                    ->whereIn('role_id', $roleIds)
                    ->delete();
            }
        }
    }
};
