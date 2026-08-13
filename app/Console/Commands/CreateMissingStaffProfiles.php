<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;

class CreateMissingStaffProfiles extends Command
{
    protected $signature = 'inventory:create-missing-staff-profiles {--dry-run}';

    protected $description = 'Create staff profiles for facility users that do not have one. Uses facility first active department when possible.';

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        $rows = DB::table('facility_user')
            ->select('user_id', 'facility_id')
            ->where('is_active', true)
            ->orderBy('user_id')
            ->get()
            ->unique('user_id');

        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $userId = $row->user_id;
            $facilityId = $row->facility_id;

            $exists = DB::table('staff_profiles')->where('user_id', $userId)->exists();
            if ($exists) {
                $skipped++;
                continue;
            }

            $dept = DepartmentModel::query()
                ->where('facility_id', $facilityId)
                ->where('status', 'active')
                ->orderBy('is_patient_facing', 'desc')
                ->first();

            $departmentName = $dept ? $dept->name : 'General';

            $this->line(sprintf('Create profile for user %s -> department: %s', $userId, $departmentName));
            if (! $dry) {
                StaffProfileModel::create([
                    'user_id' => $userId,
                    'employee_number' => 'EMP-'.$userId,
                    'department' => $departmentName,
                    'job_title' => 'Staff',
                    'employment_type' => 'permanent',
                    'status' => 'active',
                ]);
            }

            $created++;
        }

        $this->info('Done.');
        $this->info(sprintf('Scanned: %d', $rows->count()));
        $this->info(sprintf('Created: %d', $created));
        $this->info(sprintf('Skipped (already existed): %d', $skipped));

        return 0;
    }
}
