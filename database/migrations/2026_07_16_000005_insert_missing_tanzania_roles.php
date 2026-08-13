<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roles = config('roles');

        $missingCodes = [
            'CLINICAL.NURSE.MIDWIFE',
            'CLINICAL.DENTAL.OFFICER',
            'CLINICAL.SURGEON',
            'ALLIED.NUTRITIONIST',
            'ALLIED.COUNSELOR',
            'ALLIED.COMMUNITY.HEALTH.WORKER',
            'SUPPORT.MEDICAL.ATTENDANT',
            'SUPPORT.HEALTH.SECRETARY',
        ];

        foreach ($missingCodes as $code) {
            $roleDef = null;
            foreach ($roles as $def) {
                if (($def['code'] ?? null) === $code) {
                    $roleDef = $def;
                    break;
                }
            }

            if ($roleDef === null) {
                continue;
            }

            $perms = $roleDef['permissions'] ?? [];
            unset($roleDef['permissions']);

            $exists = DB::table('roles')->where('code', $code)->exists();

            if ($exists) {
                DB::table('roles')->where('code', $code)->update(array_merge($roleDef, [
                    'updated_at' => now(),
                ]));
            } else {
                continue;
            }

            $roleId = DB::table('roles')->where('code', $code)->value('id');
            if (! $roleId) {
                continue;
            }

            $permIds = DB::table('permissions')
                ->whereIn('name', $perms)
                ->pluck('id');

            foreach ($permIds as $permId) {
                DB::table('permission_role')->updateOrInsert(
                    ['permission_id' => $permId, 'role_id' => $roleId],
                );
            }
        }
    }

    public function down(): void
    {
        $missingCodes = [
            'CLINICAL.NURSE.MIDWIFE',
            'CLINICAL.DENTAL.OFFICER',
            'CLINICAL.SURGEON',
            'ALLIED.NUTRITIONIST',
            'ALLIED.COUNSELOR',
            'ALLIED.COMMUNITY.HEALTH.WORKER',
            'SUPPORT.MEDICAL.ATTENDANT',
            'SUPPORT.HEALTH.SECRETARY',
        ];

        foreach ($missingCodes as $code) {
            DB::table('permission_role')
                ->whereIn('role_id', function ($query) use ($code): void {
                    $query->select('id')->from('roles')->where('code', $code);
                })
                ->delete();

            DB::table('roles')->where('code', $code)->delete();
        }
    }
};
