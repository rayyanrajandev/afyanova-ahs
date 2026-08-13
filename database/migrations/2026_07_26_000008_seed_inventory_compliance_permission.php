<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory_MasterData_Alignment_Plan.md Phase 6. New permission gating
 * compliance-sensitive fields (cold-chain override, controlled-substance
 * classification) separately from routine item management.
 *
 * Audited actual role assignments before writing this (per the plan's own
 * "confirm before assuming" principle) rather than reusing the role-code list
 * from 2026_04_26_000001_seed_inventory_requisition_permissions_to_roles.php --
 * that migration's role codes (HOSPITAL.FACILITY.ADMIN etc.) don't exist in
 * this database at all; the RBAC system was restructured after it was
 * written, and today only PLATFORM.SUPER.ADMIN actually holds
 * inventory.procurement.manage-items in permission_role. Rather than hardcode
 * that finding (which could be wrong in a different environment with
 * different role assignments), this migration queries whichever roles
 * currently hold manage-items and grants manage-compliance to exactly those
 * roles -- so "keep existing capability" is computed from the real state of
 * the database it's running against, not assumed from this one.
 */
return new class extends Migration
{
    private const NEW_PERMISSION = 'inventory.procurement.manage-compliance';

    private const EXISTING_PERMISSION = 'inventory.procurement.manage-items';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $now = now();

        $permissionId = DB::table('permissions')->where('name', self::NEW_PERMISSION)->value('id');
        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => self::NEW_PERMISSION,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $existingPermissionId = DB::table('permissions')->where('name', self::EXISTING_PERMISSION)->value('id');
        if ($existingPermissionId === null) {
            return;
        }

        $roleIdsWithExistingCapability = DB::table('permission_role')
            ->where('permission_id', $existingPermissionId)
            ->pluck('role_id');

        foreach ($roleIdsWithExistingCapability as $roleId) {
            $alreadyGranted = DB::table('permission_role')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if ($alreadyGranted) {
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

    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', self::NEW_PERMISSION)->value('id');
        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
