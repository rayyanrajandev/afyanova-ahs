<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;

class BackfillStaffProfileDepartmentIds extends Command
{
    protected $signature = 'inventory:backfill-staff-department-ids {--dry-run}';

    protected $description = 'Backfill staff_profiles.department_id from staff_profiles.department when possible.';

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        $profiles = StaffProfileModel::query()->whereNull('department_id')->get();
        $updated = 0;
        $skipped = 0;
        foreach ($profiles as $profile) {
            $raw = trim((string) $profile->department);
            if ($raw === '') {
                $skipped++;
                continue;
            }

            $normalized = mb_strtolower($raw);
            $query = DepartmentModel::query()->where('status', 'active');
            $department = (clone $query)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                ->orWhereRaw('LOWER(TRIM(code)) = ?', [$normalized])
                ->first();

            if (! $department) {
                $tokens = array_values(array_filter(array_map('trim', preg_split('/\s+/', $raw))));
                if (count($tokens) > 0) {
                    $tokenQuery = (clone $query);
                    $tokenQuery->where(function ($b) use ($tokens) {
                        foreach ($tokens as $token) {
                            $t = mb_strtolower($token);
                            $b->orWhereRaw('LOWER(name) LIKE ?', ["%{$t}%"])
                              ->orWhereRaw('LOWER(code) LIKE ?', ["%{$t}%"]);
                        }
                    });

                    $candidates = $tokenQuery->get();
                    if ($candidates->count() === 1) {
                        $department = $candidates->first();
                    }
                }
            }

            if ($department) {
                $this->line(sprintf('Profile %s: set department_id=%s (%s)', $profile->id, $department->id, $department->name));
                if (! $dry) {
                    $profile->department_id = $department->id;
                    $profile->save();
                }
                $updated++;
            } else {
                $this->line(sprintf('Profile %s: no match for "%s"', $profile->id, $raw));
            }
        }

        $this->info('Done.');
        $this->info(sprintf('Profiles scanned: %d', $profiles->count()));
        $this->info(sprintf('Updated: %d', $updated));
        $this->info(sprintf('Skipped (blank): %d', $skipped));

        return 0;
    }
}
