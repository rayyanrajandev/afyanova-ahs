<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives outpatient triage its own permission.
 *
 * `appointments.record-triage` was a computed Gate only — it resolved from
 * `emergency.triage.create|update|update-status`, which are held by
 * CLINICAL.EMERGENCY alone. Outpatient triage was therefore gated behind
 * Emergency Department permissions, so an ordinary nurse got a 403 on
 * `appointments/{id}/claim-triage`: `triage_owner_user_id` could never be set
 * through the normal path, the "In Triage" badge was unreachable, and the
 * triage.claimed / triage.claim_released timeline entries could never be
 * written by the role that actually does the work (2026-08-16 activity audit).
 *
 * Seeding it as a real permission lets OPD triage be expressed by its own
 * name instead of borrowing the ED's. The Gate keeps its emergency branches,
 * so this widens access rather than moving it: nothing that worked stops
 * working.
 *
 * Granted to CLINICAL.NURSE here as well as in config/roles.php, because the
 * config file only reaches a database through `php artisan roles:sync` — an
 * existing facility needs the grant backfilled.
 */
return new class extends Migration
{
    private const PERMISSION = 'appointments.record-triage';

    /**
     * Roles that run outpatient triage. Deliberately not the physician roles:
     * they consume the triage handoff, they do not perform it.
     */
    private const ROLE_CODES = ['CLINICAL.NURSE', 'CLINICAL.NURSE.MIDWIFE'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION],
            ['created_at' => $now, 'updated_at' => $now],
        );

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($permissionId === null || ! Schema::hasTable('permission_role')) {
            return;
        }

        foreach (DB::table('roles')->whereIn('code', self::ROLE_CODES)->get(['id']) as $role) {
            $alreadyGranted = DB::table('permission_role')
                ->where('role_id', $role->id)
                ->where('permission_id', $permissionId)
                ->exists();

            if ($alreadyGranted) {
                continue;
            }

            DB::table('permission_role')->insert([
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);
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

        if (Schema::hasTable('permission_role')) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        }

        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
