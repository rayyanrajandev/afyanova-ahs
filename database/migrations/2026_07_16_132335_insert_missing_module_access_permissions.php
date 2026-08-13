<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'laboratory.access',
        'pharmacy.access',
        'imaging.access',
        'billing.access',
        'medical.records.draft.update',
        'radiology.orders.audit-logs.view',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::NEW_PERMISSIONS as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        // Copy role assignments from medical.records.update → medical.records.draft.update
        $oldPermId = DB::table('permissions')->where('name', 'medical.records.update')->value('id');
        $newPermId = DB::table('permissions')->where('name', 'medical.records.draft.update')->value('id');

        if ($oldPermId && $newPermId) {
            $roleIds = DB::table('permission_role')
                ->where('permission_id', $oldPermId)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    ['permission_id' => $newPermId, 'role_id' => $roleId],
                );
            }
        }

        // Copy role assignments from radiology.orders.view-audit-logs → radiology.orders.audit-logs.view
        $oldRadPermId = DB::table('permissions')->where('name', 'radiology.orders.view-audit-logs')->value('id');
        $newRadPermId = DB::table('permissions')->where('name', 'radiology.orders.audit-logs.view')->value('id');

        if ($oldRadPermId && $newRadPermId) {
            $roleIds = DB::table('permission_role')
                ->where('permission_id', $oldRadPermId)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    ['permission_id' => $newRadPermId, 'role_id' => $roleId],
                );
            }
        }
    }

    public function down(): void
    {
        $permIds = DB::table('permissions')
            ->whereIn('name', self::NEW_PERMISSIONS)
            ->pluck('id');

        DB::table('permission_role')
            ->whereIn('permission_id', $permIds)
            ->delete();

        DB::table('permissions')
            ->whereIn('name', self::NEW_PERMISSIONS)
            ->delete();
    }
};
