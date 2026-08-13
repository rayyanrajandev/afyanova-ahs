<?php

namespace App\Modules\Patient\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Allocates sequential, zero-padded, 8-digit patient MRNs.
 *
 * The sequence is persisted in `patient_mrn_sequences`, one row per scope
 * (a tenant UUID, or the fixed "global" sentinel that stands in for
 * single-tenant/dev registrations that resolve no tenant). Values are
 * consumed inside a single transaction with a row-level read lock, so
 * concurrent registrations can never observe the same number — this is the
 * DB-atomic alternative to MAX(mrn) + 1 (which races) and to the old
 * random PT… strings (which were not sequential).
 */
class PatientMrnGenerator
{
    public const GLOBAL_SCOPE = 'global';

    private const MRN_LENGTH = 8;

    public function nextForTenant(?string $tenantId): string
    {
        $scope = $this->resolveScope($tenantId);

        return DB::transaction(function () use ($scope): string {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $row = DB::table('patient_mrn_sequences')
                    ->where('scope', $scope)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    // First allocation for this scope: create the counter row.
                    // insertOrIgnore keeps concurrent first-use single-winner.
                    DB::table('patient_mrn_sequences')->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        'scope' => $scope,
                        'next_value' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Another request may have won; re-lock and read its value.
                    continue;
                }

                $value = (int) $row->next_value;

                if ($value >= 10 ** self::MRN_LENGTH) {
                    throw new RuntimeException("Patient MRN sequence exhausted for scope [{$scope}].");
                }

                DB::table('patient_mrn_sequences')
                    ->where('scope', $scope)
                    ->update([
                        'next_value' => $value + 1,
                        'updated_at' => now(),
                    ]);

                return str_pad((string) $value, self::MRN_LENGTH, '0', STR_PAD_LEFT);
            }

            throw new RuntimeException("Unable to allocate a patient MRN for scope [{$scope}].");
        });
    }

    public function resolveScope(?string $tenantId): string
    {
        return $tenantId !== null && $tenantId !== ''
            ? $tenantId
            : self::GLOBAL_SCOPE;
    }
}
