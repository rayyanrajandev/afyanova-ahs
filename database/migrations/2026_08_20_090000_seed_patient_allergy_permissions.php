<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'patient.allergies.record',
        'patient.allergies.verify',
    ];

    /**
     * @return array<string, array<int, string>>
     */
    private function grants(): array
    {
        return [
            'CLINICAL.NURSE' => ['patient.allergies.record'],
            'CLINICAL.NURSE.MIDWIFE' => ['patient.allergies.record'],
            'CLINICAL.EMERGENCY' => ['patient.allergies.record'],
            'CLINICAL.GENERAL' => ['patient.allergies.record', 'patient.allergies.verify'],
            'CLINICAL.PHYSICIAN' => ['patient.allergies.record', 'patient.allergies.verify', 'patient.allergies.manage'],
            'CLINICAL.SURGEON' => ['patient.allergies.record', 'patient.allergies.verify', 'patient.allergies.manage'],
            'ADMIN.REGISTRATION' => ['patient.allergies.record'],
            'ADMIN.FACILITY' => ['patient.allergies.record', 'patient.allergies.verify', 'patient.allergies.manage'],
            'PHARMACY.STAFF' => ['patient.allergies.record'],
            'PHARMACY.SUPERVISOR' => ['patient.allergies.record', 'patient.allergies.verify'],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $now = now();
        foreach (self::NEW_PERMISSIONS as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        foreach ($this->grants() as $roleCode => $permissions) {
            $roleIds = DB::table('roles')->where('code', $roleCode)->pluck('id');

            foreach ($permissions as $permission) {
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

        DB::table('permissions')
            ->whereIn('name', self::NEW_PERMISSIONS)
            ->delete();
    }
};
