<?php

namespace App\Console\Commands;

use App\Modules\Patient\Application\Services\PatientMrnGenerator;
use Illuminate\Console\Command;

class ResetPatientMrnSequence extends Command
{
    protected $signature = 'patients:reset-mrn-sequence
                            {--tenant= : Reset sequence for a specific tenant ID}
                            {--all : Reset sequence for all scopes}
                            {--sync : Re-sync sequence based on current MAX(patient_number) in database}';

    protected $description = 'Reset or re-sync patient MRN sequence counter (starts from 00000001 when empty)';

    public function handle(PatientMrnGenerator $generator): int
    {
        $isSync = (bool) $this->option('sync');
        $isAll = (bool) $this->option('all');
        $tenantId = $this->option('tenant');

        if ($isSync) {
            if ($isAll) {
                $results = $generator->syncAllSequencesWithDatabase();
                $this->info('Synced MRN sequences for all scopes:');
                foreach ($results as $scope => $next) {
                    $this->line(" - Scope [{$scope}]: next MRN will be " . str_pad((string) $next, 8, '0', STR_PAD_LEFT));
                }
            } else {
                $next = $generator->syncSequenceWithDatabase($tenantId);
                $scope = $generator->resolveScope($tenantId);
                $this->info("Synced MRN sequence for scope [{$scope}]: next MRN will be " . str_pad((string) $next, 8, '0', STR_PAD_LEFT));
            }

            return self::SUCCESS;
        }

        if ($isAll) {
            $generator->resetAll();
            $this->info('Successfully reset all patient MRN sequences to 00000001.');

            return self::SUCCESS;
        }

        $generator->resetForTenant($tenantId);
        $scope = $generator->resolveScope($tenantId);
        $this->info("Successfully reset patient MRN sequence for scope [{$scope}] to 00000001.");

        return self::SUCCESS;
    }
}
