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

                $hasPatients = $this->scopeHasPatients($scope);
                $maxExisting = $hasPatients ? $this->getMaxNumericMrnForScope($scope) : 0;

                if ($row === null) {
                    $initialValue = $maxExisting > 0 ? $maxExisting + 1 : 1;

                    DB::table('patient_mrn_sequences')->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        'scope' => $scope,
                        'next_value' => $initialValue,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Another request may have won; re-lock and read its value.
                    continue;
                }

                $value = (int) $row->next_value;

                // Auto-sync: If no patients exist in DB for this scope (e.g. all were deleted/truncated in DB),
                // reset sequence back to 1. If patients exist but sequence is ahead of MAX(mrn)+1, sync down.
                if (! $hasPatients && $value > 1) {
                    $value = 1;
                } elseif ($hasPatients && $value > ($maxExisting + 1)) {
                    $value = $maxExisting + 1;
                }

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

    public function resetForTenant(?string $tenantId): void
    {
        $scope = $this->resolveScope($tenantId);

        DB::transaction(function () use ($scope): void {
            $updated = DB::table('patient_mrn_sequences')
                ->where('scope', $scope)
                ->update([
                    'next_value' => 1,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                DB::table('patient_mrn_sequences')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'scope' => $scope,
                    'next_value' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function resetAll(): void
    {
        DB::table('patient_mrn_sequences')->update([
            'next_value' => 1,
            'updated_at' => now(),
        ]);
    }

    public function syncSequenceWithDatabase(?string $tenantId): int
    {
        $scope = $this->resolveScope($tenantId);

        return DB::transaction(function () use ($scope): int {
            $hasPatients = $this->scopeHasPatients($scope);
            $max = $hasPatients ? $this->getMaxNumericMrnForScope($scope) : 0;
            $nextValue = $max > 0 ? $max + 1 : 1;

            $updated = DB::table('patient_mrn_sequences')
                ->where('scope', $scope)
                ->update([
                    'next_value' => $nextValue,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                DB::table('patient_mrn_sequences')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'scope' => $scope,
                    'next_value' => $nextValue,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $nextValue;
        });
    }

    /**
     * @return array<string, int>
     */
    public function syncAllSequencesWithDatabase(): array
    {
        $scopes = DB::table('patient_mrn_sequences')->pluck('scope')->all();
        $results = [];

        foreach ($scopes as $scope) {
            $tenantId = $scope === self::GLOBAL_SCOPE ? null : $scope;
            $results[$scope] = $this->syncSequenceWithDatabase($tenantId);
        }

        return $results;
    }

    public function getMaxNumericMrnForScope(string $scope): int
    {
        $query = DB::table('patients');

        if ($scope === self::GLOBAL_SCOPE) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $scope);
        }

        $patientNumbers = $query->pluck('patient_number');
        $max = 0;

        foreach ($patientNumbers as $number) {
            if (is_string($number) && preg_match('/^[0-9]{8}$/', $number)) {
                $max = max($max, (int) $number);
            }
        }

        return $max;
    }

    public function scopeHasPatients(string $scope): bool
    {
        $query = DB::table('patients');

        if ($scope === self::GLOBAL_SCOPE) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $scope);
        }

        return $query->exists();
    }

    public function resolveScope(?string $tenantId): string
    {
        return $tenantId !== null && $tenantId !== ''
            ? $tenantId
            : self::GLOBAL_SCOPE;
    }
}
