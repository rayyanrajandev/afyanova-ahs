<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfills permissions each workspace's own action endpoints require but the
 * corresponding role never held.
 *
 * Found by extending WorkspaceRoleAccessMatrixTest from GET endpoints to action
 * endpoints (2026-08-16). Role gaps bite on actions, because actions are where
 * staff do things — and every one of these had shipped:
 *
 *  - ADMIN.REGISTRATION could not write insurance, though the registration form
 *    collects it and posts it on save. The POST 403'd and the UI fell back to
 *    "Patient registered, but insurance record could not be saved." Held only by
 *    FINANCE.CLAIMS.
 *  - No physician role held `service.requests.create`, so `clinician/orders/
 *    referral` was unreachable — a doctor could not raise a referral.
 *  - CLINICAL.NURSE could not create a medical record, so `nursing/notes/{id}`
 *    was unreachable. CLINICAL.NURSE.MIDWIFE already held it for the same
 *    nursing notes; the plain nurse role simply never did.
 *
 * Mirrors config/roles.php, which only reaches a database through
 * `php artisan roles:sync` — existing facilities need the grants backfilled.
 */
return new class extends Migration
{
    /**
     * @return array<string, array<int, string>>
     */
    private function grants(): array
    {
        return [
            'ADMIN.REGISTRATION' => ['patients.insurance.manage', 'patients.insurance.verify'],
            'CLINICAL.GENERAL' => ['service.requests.create'],
            'CLINICAL.PHYSICIAN' => ['service.requests.create'],
            'CLINICAL.SURGEON' => ['service.requests.create'],
            'CLINICAL.NURSE' => ['medical.records.create'],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        foreach ($this->grants() as $roleCode => $permissions) {
            $roleIds = DB::table('roles')->where('code', $roleCode)->pluck('id');

            foreach ($permissions as $permission) {
                // Never invent a permission here: if it is absent from the
                // catalog the grant would be a silent no-op, and inserting one
                // would hide that. All five already exist.
                $permissionId = DB::table('permissions')->where('name', $permission)->value('id');

                if ($permissionId === null) {
                    continue;
                }

                foreach ($roleIds as $roleId) {
                    $exists = DB::table('permission_role')
                        ->where('role_id', $roleId)
                        ->where('permission_id', $permissionId)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('permission_role')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permission_role')) {
            return;
        }

        foreach ($this->grants() as $roleCode => $permissions) {
            $roleIds = DB::table('roles')->where('code', $roleCode)->pluck('id');
            $permissionIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');

            DB::table('permission_role')
                ->whereIn('role_id', $roleIds)
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }
    }
};
