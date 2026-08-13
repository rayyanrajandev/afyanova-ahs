<?php

namespace App\Console\Commands;

use App\Modules\Patient\Domain\ValueObjects\PatientName;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Console\Command;

class NormalizePatientNames extends Command
{
    protected $signature = 'patients:normalize-names';

    protected $description = 'Normalize existing patient names to proper case (Title Case)';

    public function handle(): int
    {
        $total = PatientModel::query()->count();
        $this->info("Found {$total} patient records to process.");

        $processed = 0;
        $updated = 0;

        PatientModel::query()->chunk(100, function ($patients) use (&$processed, &$updated): void {
            foreach ($patients as $patient) {
                PatientModel::withoutEvents(function () use ($patient, &$processed, &$updated): void {
                    $dirty = false;

                    $normalizedFirst = PatientName::normalize($patient->first_name ?? '');
                    if ($normalizedFirst !== $patient->first_name) {
                        $patient->first_name = $normalizedFirst;
                        $dirty = true;
                    }

                    if ($patient->middle_name !== null) {
                        $normalizedMiddle = PatientName::normalize($patient->middle_name);
                        if ($normalizedMiddle !== $patient->middle_name) {
                            $patient->middle_name = $normalizedMiddle;
                            $dirty = true;
                        }
                    }

                    $normalizedLast = PatientName::normalize($patient->last_name ?? '');
                    if ($normalizedLast !== $patient->last_name) {
                        $patient->last_name = $normalizedLast;
                        $dirty = true;
                    }

                    if ($patient->next_of_kin_name !== null) {
                        $normalizedNextOfKin = PatientName::normalize($patient->next_of_kin_name);
                        if ($normalizedNextOfKin !== $patient->next_of_kin_name) {
                            $patient->next_of_kin_name = $normalizedNextOfKin;
                            $dirty = true;
                        }
                    }

                    if ($dirty) {
                        $patient->save();
                        $updated++;
                    }

                    $processed++;
                });
            }
        });

        $this->info("Processed {$processed} records, updated {$updated} names.");

        return self::SUCCESS;
    }
}
