<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Old (underscored) name => new (hyphenated) name. The previous migration
     * seeded these with the wrong module-name separator -- every enforcement
     * call site (routes, form requests, frontend) uses the hyphenated form.
     *
     * @var array<string, string>
     */
    private const RENAMED_PERMISSIONS = [
        'clinical_procedure.orders.read' => 'clinical-procedure.orders.read',
        'clinical_procedure.orders.create' => 'clinical-procedure.orders.create',
        'clinical_procedure.orders.update' => 'clinical-procedure.orders.update',
        'clinical_procedure.orders.update-status' => 'clinical-procedure.orders.update-status',
        'clinical_procedure.orders.view-audit-logs' => 'clinical-procedure.orders.view-audit-logs',
    ];

    /**
     * These were never created as permission rows at all -- the routes/form
     * requests/frontend check them, but nothing ever seeded them.
     *
     * @var array<int, string>
     */
    private const NEW_PERMISSIONS = [
        'clinical-procedure.order',
        'clinical-procedure.perform',
    ];

    /**
     * Permissions to grant (beyond the pre-existing .orders.read grant) to
     * the roles that already hold .orders.read, so the module is actually
     * usable end-to-end. Mirrors how imaging.order/imaging.perform were
     * granted alongside radiology.orders.* in 2026_07_16_000002.
     *
     * @var array<int, string>
     */
    private const ROLE_CODES = [
        'CLINICAL.PHYSICIAN',
        'CLINICAL.NURSE',
        'CLINICAL.SURGEON',
        'CLINICAL.GENERAL',
    ];

    /**
     * @var array<int, string>
     */
    private const GRANTED_PERMISSIONS = [
        'clinical-procedure.order',
        'clinical-procedure.perform',
        'clinical-procedure.orders.update',
        'clinical-procedure.orders.view-audit-logs',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::RENAMED_PERMISSIONS as $oldName => $newName) {
            $this->renamePermission($oldName, $newName);
        }

        $now = now();
        foreach (self::NEW_PERMISSIONS as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        foreach (self::ROLE_CODES as $roleCode) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            if ($roleId === null) {
                continue;
            }

            foreach (self::GRANTED_PERMISSIONS as $permissionName) {
                $permissionId = DB::table('permissions')->where('name', $permissionName)->value('id');
                if ($permissionId === null) {
                    continue;
                }

                DB::table('permission_role')->updateOrInsert(
                    ['permission_id' => $permissionId, 'role_id' => $roleId],
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $grantedIds = DB::table('permissions')->whereIn('name', self::GRANTED_PERMISSIONS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $grantedIds)->delete();

        DB::table('permissions')->whereIn('name', self::NEW_PERMISSIONS)->delete();

        foreach (self::RENAMED_PERMISSIONS as $oldName => $newName) {
            $newId = DB::table('permissions')->where('name', $newName)->value('id');
            if ($newId === null) {
                continue;
            }

            if (DB::table('permissions')->where('name', $oldName)->exists()) {
                // Renaming back would collide with an existing row -- leave it as-is.
                continue;
            }

            DB::table('permissions')->where('id', $newId)->update(['name' => $oldName]);
        }
    }

    /**
     * Rename $oldName to $newName, tolerating environments (like a cloud DB
     * seeded from a different snapshot) where a permission row named
     * $newName already exists. In that case, re-point any role grants that
     * only reference the old row onto the existing new row, then drop the
     * old row -- the permission_role FK cascades, so any leftover grants for
     * roles that already held the new permission are cleaned up for free.
     */
    private function renamePermission(string $oldName, string $newName): void
    {
        $oldId = DB::table('permissions')->where('name', $oldName)->value('id');
        if ($oldId === null) {
            return;
        }

        $newId = DB::table('permissions')->where('name', $newName)->value('id');
        if ($newId === null) {
            DB::table('permissions')->where('id', $oldId)->update(['name' => $newName]);

            return;
        }

        if ($oldId === $newId) {
            return;
        }

        $roleIdsAlreadyOnNew = DB::table('permission_role')->where('permission_id', $newId)->pluck('role_id');
        DB::table('permission_role')
            ->where('permission_id', $oldId)
            ->whereNotIn('role_id', $roleIdsAlreadyOnNew)
            ->update(['permission_id' => $newId]);

        DB::table('permissions')->where('id', $oldId)->delete();
    }
};
