<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roles = config('roles');

        if (empty($roles)) {
            return;
        }

        foreach ($roles as $roleDef) {
            $perms = $roleDef['permissions'] ?? [];
            unset($roleDef['permissions']);

            $roleDef['updated_at'] = now();

            $existingRole = DB::table('roles')->where('code', $roleDef['code'])->first();

            if (! $existingRole) {
                continue;
            }

            DB::table('roles')
                ->where('code', $roleDef['code'])
                ->update(array_merge($roleDef, [
                    'created_at' => $existingRole->created_at ?? now(),
                ]));

            $roleId = $existingRole->id;

            $permIds = DB::table('permissions')
                ->whereIn('name', $perms)
                ->pluck('id');

            if ($permIds->isEmpty()) {
                continue;
            }

            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->whereNotIn('permission_id', $permIds)
                ->delete();

            foreach ($permIds as $permId) {
                DB::table('permission_role')->updateOrInsert(
                    ['permission_id' => $permId, 'role_id' => $roleId],
                );
            }
        }
    }

    public function down(): void
    {
        // Cannot reliably reverse — roles should be re-seeded
    }
};
