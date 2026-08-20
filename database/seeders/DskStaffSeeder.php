<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DskStaffSeeder extends Seeder
{
    public function run(): void
    {
        $facility = FacilityModel::where('code', 'DSK')->first();

        if (!$facility) {
            $this->command?->error('DSK facility not found. Run InitialFacilitySeeder first.');
            return;
        }

        $departments = DepartmentModel::where('facility_id', $facility->id)->get()->keyBy('code');

        $staff = [
            ['name' => 'Administrator', 'tag' => 'admin', 'job_title' => 'Facility Administrator', 'department_code' => 'ADM', 'facility_role' => 'super_admin', 'role_code' => 'ADMIN.FACILITY', 'is_primary' => true],
            ['name' => 'Colini Kenedy', 'tag' => 'colini', 'job_title' => 'Laboratory Technician', 'department_code' => 'LAB', 'facility_role' => 'lab_technician', 'role_code' => 'LAB.STAFF'],
            ['name' => 'Devotha Peter', 'tag' => 'devotha', 'job_title' => 'Nurse & Receptionist', 'department_code' => 'NRS', 'facility_role' => 'nurse', 'role_code' => 'CLINICAL.NURSE', 'additional_role_code' => 'ADMIN.REGISTRATION'],
            ['name' => 'Dr. Emmily Rwamuhuru', 'tag' => 'emmily', 'job_title' => 'Clinical Officer', 'department_code' => 'OPD', 'facility_role' => 'clinical_officer', 'role_code' => 'CLINICAL.GENERAL'],
            ['name' => 'Dr. Ruben', 'tag' => 'ruben', 'job_title' => 'Clinical Officer', 'department_code' => 'OPD', 'facility_role' => 'clinical_officer', 'role_code' => 'CLINICAL.GENERAL'],
            ['name' => 'Dr. Samwel Justin', 'tag' => 'samwel', 'job_title' => 'Clinical Officer', 'department_code' => 'OPD', 'facility_role' => 'clinical_officer', 'role_code' => 'CLINICAL.GENERAL'],
            ['name' => 'Given Aidan', 'tag' => 'given', 'job_title' => 'Nurse & Receptionist', 'department_code' => 'NRS', 'facility_role' => 'nurse', 'role_code' => 'CLINICAL.NURSE', 'additional_role_code' => 'ADMIN.REGISTRATION'],
            ['name' => 'Iddi Kimweri', 'tag' => 'financial', 'job_title' => 'Accountant', 'department_code' => 'FIN', 'facility_role' => 'accountant', 'role_code' => 'FINANCE.OFFICER'],
            ['name' => 'Joyce Jonathan', 'tag' => 'joyce', 'job_title' => 'Laboratory Technician', 'department_code' => 'LAB', 'facility_role' => 'lab_technician', 'role_code' => 'LAB.STAFF'],
            ['name' => 'Rajani Diwani', 'tag' => 'kibaso', 'job_title' => 'Cashier', 'department_code' => 'FIN', 'facility_role' => 'cashier', 'role_code' => 'FINANCE.CASHIER'],
            ['name' => 'Kisa Patson', 'tag' => 'kisa', 'job_title' => 'Medical Attendant', 'department_code' => 'NRS', 'facility_role' => 'medical_attendant', 'role_code' => 'SUPPORT.MEDICAL.ATTENDANT'],
            ['name' => 'Zaituni Chiundo', 'tag' => 'zaituni', 'job_title' => 'Medical Attendant', 'department_code' => 'NRS', 'facility_role' => 'medical_attendant', 'role_code' => 'SUPPORT.MEDICAL.ATTENDANT'],
            ['name' => 'Neema Mushi', 'tag' => 'neema', 'job_title' => 'Radiographer', 'department_code' => 'RAD', 'facility_role' => 'radiographer', 'role_code' => 'RADIOLOGY.STAFF'],
            ['name' => 'Dr. Dennis Kimaro', 'tag' => 'dennis', 'job_title' => 'Consultant Radiologist / Supervisor', 'department_code' => 'RAD', 'facility_role' => 'radiologist', 'role_code' => 'RADIOLOGY.SUPERVISOR'],
            ['name' => 'Fadhili Bakari', 'tag' => 'fadhili', 'job_title' => 'Pharmaceutical Technician / Dispenser', 'department_code' => 'PHA', 'facility_role' => 'dispenser', 'role_code' => 'PHARMACY.STAFF'],
            ['name' => 'Dr. Irene Kimaro', 'tag' => 'irene', 'job_title' => 'Pharmacist-in-Charge / Supervisor', 'department_code' => 'PHA', 'facility_role' => 'pharmacist', 'role_code' => 'PHARMACY.SUPERVISOR'],
        ];

        $created = 0;

        foreach ($staff as $person) {
            $department = $departments->get($person['department_code']);

            if (!$department) {
                $this->command?->error("Department {$person['department_code']} not found for {$person['name']}, skipping.");
                continue;
            }

            $email = "dskdispensary+{$person['tag']}@gmail.com";
            $password = $person['tag'].'2026';

            $user = User::query()->firstOrNew(['email' => $email]);
            $user->fill([
                'name' => $person['name'],
                'password' => $password,
                'status' => 'active',
                'tenant_id' => $facility->tenant_id,
            ]);
            $user->email_verified_at = now();
            $user->save();

            // Attach RBAC role
            if (!empty($person['role_code'])) {
                $role = RoleModel::where('code', $person['role_code'])->first();
                if ($role) {
                    $user->roles()->syncWithoutDetaching([$role->id]);
                }
            }

            if (!empty($person['additional_role_code'])) {
                $addRole = RoleModel::where('code', $person['additional_role_code'])->first();
                if ($addRole) {
                    $user->roles()->syncWithoutDetaching([$addRole->id]);
                }
            }

            DB::table('facility_user')->updateOrInsert(
                ['facility_id' => $facility->id, 'user_id' => $user->id],
                [
                    'role' => $person['facility_role'],
                    'is_primary' => $person['is_primary'] ?? false,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            StaffProfileModel::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'tenant_id' => $facility->tenant_id,
                    'department_id' => $department->id,
                    'department' => $department->name,
                    'job_title' => $person['job_title'],
                    'employment_type' => 'full_time',
                    'status' => 'active',
                    'employee_number' => $this->generateEmployeeNumber(),
                ],
            );

            $created++;
            $this->command?->info("Created {$person['name']} ({$email}) - {$person['job_title']} / {$department->name}");
        }

        $this->command?->info("Seeded {$created} staff members for DSK Dispensary.");
    }

    private function generateEmployeeNumber(): string
    {
        do {
            $candidate = 'STF'.now()->format('Ymd').strtoupper(Str::random(6));
        } while (StaffProfileModel::where('employee_number', $candidate)->exists());

        return $candidate;
    }
}
