<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;

class SetStaffDepartment extends Command
{
    protected $signature = 'inventory:set-staff-department {userId} {departmentName}';

    protected $description = 'Set a staff profile department to a canonical department name for a given user id.';

    public function handle(): int
    {
        $userId = $this->argument('userId');
        $departmentName = $this->argument('departmentName');

        $user = User::find($userId);
        if (! $user) {
            $this->error('User not found');
            return 1;
        }

        $dept = DepartmentModel::query()->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($departmentName)])->first();
        if (! $dept) {
            $this->error('Department not found: '.$departmentName);
            return 1;
        }

        $profile = $user->staffProfile;
        if (! $profile) {
            $this->info('No staff profile found for user, creating one.');
            $profile = StaffProfileModel::create([
                'user_id' => $user->id,
                'employee_number' => 'EMP-'.$user->id,
                'department' => $dept->name,
                'job_title' => 'Staff',
                'employment_type' => 'permanent',
                'status' => 'active',
            ]);
            $this->info('Created staff profile '.$profile->id);
            return 0;
        }

        $profile->department = $dept->name;
        $profile->save();

        $this->info('Updated staff profile for user '.$user->id.' to department '.$dept->name);
        return 0;
    }
}
