<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Direct Service Queue V2's hard department enforcement (see
 * ServiceRequestDepartmentScopeResolver) — without this permission, an
 * actor is hard-scoped to their own staff_profiles.department_id. Granted
 * to the supervisor-tier role only; ordinary lab/pharmacy/radiology/theatre
 * staff (HOSPITAL.LABORATORY.USER etc., granted service.requests.* by
 * 2026_05_02_000002_seed_service_request_permissions.php) are department-
 * scoped by default.
 */
return new class extends Migration
{
    private const PERMISSION = 'service.requests.view-all-departments';

    private const ROLE_CODES = ['HOSPITAL.FACILITY.ADMIN'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');
        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => self::PERMISSION,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('permissions')->where('id', $permissionId)->update(['updated_at' => $now]);
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $roleIds = DB::table('roles')->whereIn('code', self::ROLE_CODES)->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');
        if ($permissionId === null) {
            return;
        }

        if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
            $roleIds = DB::table('roles')->whereIn('code', self::ROLE_CODES)->pluck('id');
            DB::table('permission_role')
                ->where('permission_id', $permissionId)
                ->whereIn('role_id', $roleIds)
                ->delete();
        }
    }
};
