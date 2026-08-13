<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use Illuminate\Support\Facades\DB;

class AlignStaffDepartments extends Command
{
    protected $signature = 'inventory:align-staff-departments {--dry-run}';

    protected $description = 'Align free-text staff_profiles.department values to canonical department registry names where possible.';

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        $this->info('Scanning staff profiles for department normalization...');

        $profiles = StaffProfileModel::query()->whereNotNull('department')->get();
        $updated = 0;
        $skipped = 0;
        $unmatched = [];

        foreach ($profiles as $profile) {
            $raw = trim((string) $profile->department);
            if ($raw === '') {
                $skipped++;
                continue;
            }

            $normalized = mb_strtolower($raw);

            $query = DepartmentModel::query()->where('status', 'active');

            // Exact match on name or code
            $department = (clone $query)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                ->orWhereRaw('LOWER(TRIM(code)) = ?', [$normalized])
                ->first();

            if (! $department) {
                // Tokenized substring fallback
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
                $canonical = (string) $department->name;
                if ($canonical !== $profile->department) {
                    $this->line(sprintf('%s -> %s', $profile->department, $canonical));
                    if (! $dry) {
                        $profile->department = $canonical;
                        $profile->save();
                    }
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                $unmatched[] = ['id' => $profile->id, 'user_id' => $profile->user_id, 'department' => $profile->department];
            }
        }

        $this->info('Done.');
        $this->info(sprintf('Profiles scanned: %d', $profiles->count()));
        $this->info(sprintf('Updated: %d', $updated));
        $this->info(sprintf('Skipped/unchanged: %d', $skipped));
        $this->info(sprintf('Unmatched: %d', count($unmatched)));

        if (count($unmatched) > 0) {
            $this->line('Unmatched profiles (id, user_id, department):');
            foreach ($unmatched as $row) {
                $this->line(sprintf('%s, %s, %s', $row['id'], $row['user_id'], $row['department']));
            }
        }

        return 0;
    }
}
