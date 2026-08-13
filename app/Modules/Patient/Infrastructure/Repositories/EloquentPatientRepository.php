<?php

namespace App\Modules\Patient\Infrastructure\Repositories;

use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Patient\Domain\ValueObjects\PatientPhoneNumber;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EloquentPatientRepository implements PatientRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $patient = new PatientModel();

        // Bulk-restore (PatientCsvSchema/BulkImportPatientsUseCase) needs to
        // preserve the original UUID so foreign keys in other modules
        // (appointments, admissions, service requests, etc.) stay valid
        // across a backup/restore cycle. `id` isn't in $fillable, so it's
        // set directly here rather than via fill(); normal registration
        // (CreatePatientUseCase) never passes it, leaving HasUuids' default
        // auto-generation untouched for that flow.
        if (isset($attributes['id'])) {
            $patient->id = $attributes['id'];
            unset($attributes['id']);
        }

        $patient->fill($attributes);
        $patient->save();

        return $patient->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = PatientModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $patient = $query->find($id);

        return $patient?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = PatientModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $patient = $query->find($id);

        if (! $patient) {
            return null;
        }

        $patient->fill($attributes);
        $patient->save();

        return $patient->toArray();
    }

    public function existsByPatientNumber(string $patientNumber): bool
    {
        return PatientModel::query()
            ->where('patient_number', $patientNumber)
            ->exists();
    }

    public function search(
        ?string $query,
        ?string $status,
        ?string $gender,
        ?string $region,
        ?string $district,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
        ?string $registrationWindow = null,
        ?string $ageGroup = null,
        ?string $insuranceType = null,
    ): array {
        $sortBy = in_array($sortBy, ['created_at', 'updated_at', 'first_name', 'last_name', 'patient_number'], true)
            ? $sortBy
            : 'created_at';

        $queryBuilder = PatientModel::query()
            ->with('openEncounter')
            ->withMax('encounters as last_visit_at', 'opened_at')
            ->when(
                $this->isPlatformScopingEnabled(),
                fn (Builder $builder) => $this->platformScopeQueryApplier->apply(
                    $builder,
                    tenantColumn: 'tenant_id',
                    facilityColumn: null,
                ),
            )
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applySearchPredicate($builder, $searchTerm))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($gender, fn (Builder $builder, string $requestedGender) => $builder->where('gender', $requestedGender))
            ->when($region, function (Builder $builder, string $requestedRegion): void {
                $builder->whereRaw('LOWER(COALESCE(region, \'\')) LIKE ?', ['%'.mb_strtolower($requestedRegion).'%']);
            })
            ->when($district, function (Builder $builder, string $requestedDistrict): void {
                $builder->whereRaw('LOWER(COALESCE(district, \'\')) LIKE ?', ['%'.mb_strtolower($requestedDistrict).'%']);
            })
            ->when($registrationWindow, fn (Builder $builder, string $window) => $this->applyRegistrationWindow($builder, $window))
            ->when($ageGroup, fn (Builder $builder, string $group) => $this->applyAgeGroup($builder, $group))
            ->when($insuranceType, fn (Builder $builder, string $type) => $this->applyInsuranceType($builder, $type))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCounts(?string $query): array
    {
        $queryBuilder = PatientModel::query()
            ->when(
                $this->isPlatformScopingEnabled(),
                fn (Builder $builder) => $this->platformScopeQueryApplier->apply(
                    $builder,
                    tenantColumn: 'tenant_id',
                    facilityColumn: null,
                ),
            )
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applySearchPredicate($builder, $searchTerm));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'active' => 0,
            'inactive' => 0,
            'other' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if ($status === 'active' || $status === 'inactive') {
                $counts[$status] += $aggregate;
            } else {
                $counts['other'] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    /**
     * Shared by search() and statusCounts() — was duplicated verbatim
     * between the two before. Matches name/patient-number/email/national-id
     * via the existing broad `%term%` LIKE, but phone matching now goes
     * through the indexed `phone_normalized` column (prefix match) instead
     * of a raw, unindexable `LIKE` on the stored `phone` string — the raw
     * `phone` LIKE stays too, as a safety net for any row whose
     * `phone_normalized` backfill hasn't run yet.
     */
    /**
     * search_text (a DB-generated, trigram-indexed column — see
     * database/migrations/2026_07_19_000001_add_trigram_search_index_to_patients_table.php)
     * already lowercases and concatenates exactly the same 9 fields/expressions
     * this method used to OR together as separate leading-wildcard
     * LOWER(x) LIKE conditions — none of which could use a plain B-tree
     * index. One indexed substring match against that column replaces all 9.
     *
     * Bug found and fixed 2026-08-11: that migration only creates
     * `search_text` on Postgres (pg_trgm is Postgres-only) — it's a
     * documented no-op everywhere else, including this app's own
     * SQLite-backed local dev DB and the `:memory:` SQLite test suite
     * runs against. Querying `search_text` unconditionally meant every
     * search request 500'd outside Postgres — confirmed live (typing
     * anything into Reception's patient search) and confirmed as a
     * pre-existing failing test (12 of PatientApiTest's assertions were
     * already red on `no such column: search_text` before this fix, not
     * something this touch introduced). `searchTextColumnAvailable()`
     * now branches: Postgres gets the fast indexed column as before;
     * everything else falls back to the original 9 unindexed LIKE
     * conditions the column was built to replace — slower, but correct,
     * and this path only runs in dev/test where the dataset is small.
     * Production always runs Postgres and takes the fast path,
     * unchanged.
     */
    private function applySearchPredicate(Builder $builder, string $searchTerm): void
    {
        $normalizedSearchTerm = mb_strtolower(trim($searchTerm));
        $like = '%'.$normalizedSearchTerm.'%';
        $normalizedPhoneTerm = PatientPhoneNumber::normalize($searchTerm);

        $builder->where(function (Builder $nestedQuery) use ($like, $normalizedPhoneTerm): void {
            if ($this->searchTextColumnAvailable()) {
                $nestedQuery->whereRaw('search_text LIKE ?', [$like]);
            } else {
                $this->applySearchTextFallback($nestedQuery, $like);
            }

            if ($normalizedPhoneTerm !== '') {
                $nestedQuery->orWhere('phone_normalized', 'LIKE', $normalizedPhoneTerm.'%');
            }
        });
    }

    private function searchTextColumnAvailable(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * The pre-`search_text` implementation, reconstructed: the same 9
     * fields/expressions the generated column concatenates, as separate
     * OR'd LIKE conditions instead of one indexed substring match.
     * `||` string concatenation is supported identically by SQLite and
     * Postgres (unlike MySQL's CONCAT()), so this doesn't need its own
     * per-driver branch on top of the one in searchTextColumnAvailable().
     */
    private function applySearchTextFallback(Builder $nestedQuery, string $like): void
    {
        $nestedQuery
            ->whereRaw('LOWER(patient_number) LIKE ?', [$like])
            ->orWhereRaw('LOWER(first_name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(middle_name, \'\')) LIKE ?', [$like])
            ->orWhereRaw('LOWER(first_name || \' \' || last_name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(first_name || \' \' || COALESCE(middle_name, \'\') || \' \' || last_name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(national_id, \'\')) LIKE ?', [$like]);
    }

    private function applyRegistrationWindow(Builder $builder, string $window): void
    {
        $now = Carbon::now();

        $from = match ($window) {
            'today' => $now->copy()->startOfDay(),
            'this_week' => $now->copy()->startOfWeek(),
            'this_month' => $now->copy()->startOfMonth(),
            default => null,
        };

        if ($from !== null) {
            $builder->where('created_at', '>=', $from);
        }
    }

    private function applyAgeGroup(Builder $builder, string $group): void
    {
        $today = Carbon::now()->toDateString();

        match ($group) {
            'child' => $builder->whereDate('date_of_birth', '>', Carbon::parse($today)->subYears(18)->toDateString()),
            'adult' => $builder
                ->whereDate('date_of_birth', '<=', Carbon::parse($today)->subYears(18)->toDateString())
                ->whereDate('date_of_birth', '>', Carbon::parse($today)->subYears(60)->toDateString()),
            'elderly' => $builder->whereDate('date_of_birth', '<=', Carbon::parse($today)->subYears(60)->toDateString()),
            default => null,
        };
    }

    /**
     * `patient_insurance_records` (insurance_type: private/nhif/other/none,
     * status: active/inactive/expired/cancelled) already indexes
     * (insurance_type, status) — "insurance" is at least one active,
     * non-`none` record; "cash" is its exact complement.
     */
    private function applyInsuranceType(Builder $builder, string $type): void
    {
        $hasActiveInsurance = function (QueryBuilder $exists): void {
            $exists->from('patient_insurance_records')
                ->whereColumn('patient_insurance_records.patient_id', 'patients.id')
                ->where('patient_insurance_records.status', 'active')
                ->where('patient_insurance_records.insurance_type', '!=', 'none');
        };

        if ($type === 'insurance') {
            $builder->whereExists($hasActiveInsurance);
        } elseif ($type === 'cash') {
            $builder->whereNotExists($hasActiveInsurance);
        }
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        $data = array_map(static function (PatientModel $patient): array {
            $array = $patient->toArray();
            $array['care_status'] = $patient->openEncounter?->type;

            return $array;
        }, $paginator->items());

        return [
            'data' => $data,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }

    public function findActiveHardDuplicateIdentifiers(
        ?string $nationalId,
        ?string $patientNumber = null,
        ?string $excludePatientId = null
    ): array {
        $matches = [];

        $normalizedNationalId = $this->normalizeIdentifier($nationalId);
        if ($normalizedNationalId !== '') {
            $query = $this->activeDuplicateQuery($excludePatientId)
                ->whereRaw($this->normalizedIdentifierSql('national_id').' = ?', [$normalizedNationalId]);

            foreach ($query->limit(5)->get() as $patient) {
                if ($this->normalizeIdentifier($patient->national_id) === $normalizedNationalId) {
                    $matches[$patient->id] = $patient->toArray();
                }
            }
        }

        $normalizedPatientNumber = $this->normalizeIdentifier($patientNumber);
        if ($normalizedPatientNumber !== '') {
            $query = $this->activeDuplicateQuery($excludePatientId)
                ->whereRaw($this->normalizedIdentifierSql('patient_number').' = ?', [$normalizedPatientNumber]);

            foreach ($query->limit(5)->get() as $patient) {
                if ($this->normalizeIdentifier($patient->patient_number) === $normalizedPatientNumber) {
                    $matches[$patient->id] = $patient->toArray();
                }
            }
        }

        return array_slice(array_values($matches), 0, 5);
    }

    public function findActiveDuplicateCandidates(
        ?string $firstName,
        ?string $lastName,
        ?string $dateOfBirth,
        ?string $phone,
        ?string $gender,
        ?string $addressLine,
        ?string $excludePatientId = null
    ): array {
        $matches = [];
        $normalizedPhone = PatientPhoneNumber::normalize($phone);

        if ($normalizedPhone !== '') {
            $phoneCandidates = $this->phoneSearchCandidates($normalizedPhone);

            $query = $this->activeDuplicateQuery($excludePatientId)
                ->whereRaw(
                    $this->normalizedPhoneSql('phone').' in ('.implode(',', array_fill(0, count($phoneCandidates), '?')).')',
                    $phoneCandidates,
                );

            foreach ($query->limit(5)->get() as $patient) {
                if (PatientPhoneNumber::normalize($patient->phone) === $normalizedPhone) {
                    $matches[$patient->id] = $patient->toArray();
                }
            }
        }

        $normalizedFirstName = $this->normalizeText($firstName);
        $normalizedLastName = $this->normalizeText($lastName);
        $normalizedDateOfBirth = trim((string) $dateOfBirth);
        $normalizedGender = $this->normalizeText($gender);
        $normalizedAddressLine = $this->normalizeText($addressLine);

        $hasDemographicCandidateKey = ($normalizedFirstName !== '' && $normalizedLastName !== '')
            || ($normalizedLastName !== '' && $normalizedDateOfBirth !== '')
            || ($normalizedFirstName !== '' && $normalizedDateOfBirth !== '')
            || ($normalizedGender !== '' && $normalizedAddressLine !== '');

        if ($hasDemographicCandidateKey) {
            $query = $this->activeDuplicateQuery($excludePatientId)
                ->where(function (Builder $builder) use (
                    $normalizedFirstName,
                    $normalizedLastName,
                    $normalizedDateOfBirth,
                    $normalizedGender,
                    $normalizedAddressLine
                ): void {
                    if ($normalizedFirstName !== '' && $normalizedLastName !== '') {
                        $builder->orWhere(function (Builder $nested) use ($normalizedFirstName, $normalizedLastName): void {
                            $nested
                                ->whereRaw('LOWER(TRIM(first_name)) = ?', [$normalizedFirstName])
                                ->whereRaw('LOWER(TRIM(last_name)) = ?', [$normalizedLastName]);
                        });
                    }

                    if ($normalizedLastName !== '' && $normalizedDateOfBirth !== '') {
                        $builder->orWhere(function (Builder $nested) use ($normalizedLastName, $normalizedDateOfBirth): void {
                            $nested
                                ->whereRaw('LOWER(TRIM(last_name)) = ?', [$normalizedLastName])
                                ->whereDate('date_of_birth', $normalizedDateOfBirth);
                        });
                    }

                    if ($normalizedFirstName !== '' && $normalizedDateOfBirth !== '') {
                        $builder->orWhere(function (Builder $nested) use ($normalizedFirstName, $normalizedDateOfBirth): void {
                            $nested
                                ->whereRaw('LOWER(TRIM(first_name)) = ?', [$normalizedFirstName])
                                ->whereDate('date_of_birth', $normalizedDateOfBirth);
                        });
                    }

                    if ($normalizedGender !== '' && $normalizedAddressLine !== '') {
                        $builder->orWhere(function (Builder $nested) use ($normalizedGender, $normalizedAddressLine): void {
                            $nested
                                ->whereRaw('LOWER(TRIM(gender)) = ?', [$normalizedGender])
                                ->whereRaw('LOWER(TRIM(COALESCE(address_line, \'\'))) = ?', [$normalizedAddressLine]);
                        });
                    }
                });

            foreach ($query->limit(25)->get() as $patient) {
                $matches[$patient->id] = $patient->toArray();
            }
        }

        return array_slice(array_values($matches), 0, 5);
    }

    private function activeDuplicateQuery(?string $excludePatientId = null): Builder
    {
        $query = PatientModel::query()->where('status', 'active');
        $this->applyTenantScopeIfEnabled($query);

        if ($excludePatientId !== null && trim($excludePatientId) !== '') {
            $query->where('id', '!=', $excludePatientId);
        }

        return $query;
    }

    private function normalizedIdentifierSql(string $column): string
    {
        return "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE({$column}, '')), '-', ''), ' ', ''), '/', ''), '.', ''), '_', ''), ':', ''))";
    }

    private function normalizedPhoneSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE({$column}, '')), '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', '')";
    }

    private function normalizeIdentifier(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]+/i', '', mb_strtolower(trim((string) $value))) ?? '';
    }

    private function normalizeText(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $value))) ?? '';
    }

    /**
     * @return array<int, string>
     */
    private function phoneSearchCandidates(string $normalizedPhone): array
    {
        $candidates = [$normalizedPhone];

        if (strlen($normalizedPhone) === 12 && str_starts_with($normalizedPhone, '255')) {
            $local = '0'.substr($normalizedPhone, 3);
            $withoutCountryCode = substr($normalizedPhone, 3);

            $candidates[] = $local;
            $candidates[] = $withoutCountryCode;
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function applyTenantScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply(
            $query,
            tenantColumn: 'tenant_id',
            facilityColumn: null,
        );
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
