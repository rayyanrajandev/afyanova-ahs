<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WorkspaceTestUsersSeeder extends Seeder
{
    public function run(): void
    {
        $facilities = FacilityModel::all();
        if ($facilities->isEmpty()) {
            $this->command?->error('No facility found. Please seed facilities first.');
            return;
        }

        $primaryFacility = FacilityModel::where('code', 'DSK')->first() ?? $facilities->first();
        $password = Hash::make('DevPass!2026');

        $users = [
            [
                'name' => 'Super Admin',
                'email' => 'admin@local.test',
                'role_code' => 'PLATFORM.SUPER.ADMIN',
                'facility_role' => 'super_admin',
                'is_platform_admin' => true,
            ],
            [
                'name' => 'Receptionist Test',
                'email' => 'receptionist@local.test',
                'role_code' => 'ADMIN.REGISTRATION',
                'facility_role' => 'registration_clerk',
                'is_platform_admin' => false,
            ],
            [
                'name' => 'Nurse Test',
                'email' => 'nurse@local.test',
                'role_code' => 'CLINICAL.NURSE',
                'facility_role' => 'nurse',
                'is_platform_admin' => false,
            ],
            [
                'name' => 'Dr. Clinician Test',
                'email' => 'clinician@local.test',
                'role_code' => 'CLINICAL.PHYSICIAN',
                'facility_role' => 'clinical_officer',
                'is_platform_admin' => false,
            ],
            [
                'name' => 'Lab Technician Test',
                'email' => 'lab@local.test',
                'role_code' => 'LAB.STAFF',
                'additional_role_code' => 'LAB.SUPERVISOR',
                'facility_role' => 'lab_technician',
                'is_platform_admin' => false,
            ],
            [
                'name' => 'Lab Supervisor Test',
                'email' => 'lab.supervisor@local.test',
                'role_code' => 'LAB.SUPERVISOR',
                'facility_role' => 'lab_supervisor',
                'is_platform_admin' => false,
            ],
            [
                'name' => 'Radiographer Test',
                'email' => 'radiology@local.test',
                'role_code' => 'RADIOLOGY.STAFF',
                'facility_role' => 'radiographer',
                'is_platform_admin' => false,
            ],
            [
                'name' => 'Radiologist / Supervisor Test',
                'email' => 'radiology.supervisor@local.test',
                'role_code' => 'RADIOLOGY.SUPERVISOR',
                'facility_role' => 'radiologist',
                'is_platform_admin' => false,
            ],
            [
                'name' => 'Pharmacy Dispenser Test',
                'email' => 'pharmacy@local.test',
                'role_code' => 'PHARMACY.STAFF',
                'facility_role' => 'dispenser',
                'is_platform_admin' => false,
            ],
            [
                'name' => 'Pharmacy Supervisor Test',
                'email' => 'pharmacy.supervisor@local.test',
                'role_code' => 'PHARMACY.SUPERVISOR',
                'facility_role' => 'pharmacist',
                'is_platform_admin' => false,
            ],
            [
                'name' => 'Cashier Test',
                'email' => 'cashier@local.test',
                'role_code' => 'FINANCE.CASHIER',
                'facility_role' => 'cashier',
                'is_platform_admin' => false,
            ],
        ];

        foreach ($users as $cfg) {
            $user = User::query()->firstOrNew(['email' => $cfg['email']]);
            $user->fill([
                'name' => $cfg['name'],
                'password' => $password,
                'status' => 'active',
                'tenant_id' => $primaryFacility->tenant_id,
                'is_platform_admin' => $cfg['is_platform_admin'],
            ]);
            $user->email_verified_at = now();
            $user->save();

            // Attach RBAC role if present
            if (!empty($cfg['role_code'])) {
                $role = RoleModel::where('code', $cfg['role_code'])->first();
                if ($role) {
                    $user->roles()->syncWithoutDetaching([$role->id]);
                }
            }
            if (!empty($cfg['additional_role_code'])) {
                $addRole = RoleModel::where('code', $cfg['additional_role_code'])->first();
                if ($addRole) {
                    $user->roles()->syncWithoutDetaching([$addRole->id]);
                }
            }

            // Assign to all facilities so cookie mismatches never cause 403
            foreach ($facilities as $f) {
                DB::table('facility_user')->updateOrInsert(
                    ['facility_id' => $f->id, 'user_id' => $user->id],
                    [
                        'role' => $cfg['facility_role'],
                        'is_primary' => $f->id === $primaryFacility->id,
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            $this->command?->info("Configured {$cfg['name']} ({$cfg['email']}) with password DevPass!2026 across {$facilities->count()} facilities");
        }
    }
}
