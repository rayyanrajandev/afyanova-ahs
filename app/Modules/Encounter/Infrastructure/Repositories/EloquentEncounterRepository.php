<?php

namespace App\Modules\Encounter\Infrastructure\Repositories;

use App\Modules\Encounter\Domain\Repositories\EncounterRepositoryInterface;
use App\Modules\Encounter\Domain\ValueObjects\EncounterStatus;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentEncounterRepository implements EncounterRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function search(
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?int $primaryClinicianUserId,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['encounter_number', 'status', 'opened_at', 'closed_at', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'opened_at';

        $queryBuilder = EncounterModel::query()->with([
            'patient:id,patient_number,first_name,middle_name,last_name',
            'primaryClinician:id,name',
        ]);
        $this->applyPlatformScopeIfEnabled($queryBuilder);
        $this->applyFilters($queryBuilder, $query, $patientId, $status, $primaryClinicianUserId, $fromDateTime, $toDateTime);

        $queryBuilder->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    /**
     * The latest medical record per encounter, for the page of encounters
     * just fetched. Deliberately not an Eloquent latestOfMany() relation —
     * that relies on MAX() over the model's primary key as a tie-breaker,
     * and Postgres has no MAX(uuid) aggregate (confirmed via a real query
     * failure, not a guess). A plain "fetch ordered, keep first per group"
     * query sidesteps that entirely and is simple enough not to need the
     * abstraction.
     *
     * @param  array<int, string>  $encounterIds
     * @return array<string, array<string, mixed>>
     */
    private function latestMedicalRecordsByEncounterId(array $encounterIds): array
    {
        if ($encounterIds === []) {
            return [];
        }

        // Tie-break on id (a time-ordered UUIDv7 — see HasUuids) since two
        // records created within the same created_at timestamp resolution
        // would otherwise sort ambiguously (caught via a real test, not
        // theoretical: two records created in the same request landed on an
        // identical created_at value and the plain created_at sort picked
        // the wrong one).
        $records = MedicalRecordModel::query()
            ->select(['id', 'encounter_id', 'status', 'record_type', 'record_number'])
            ->whereIn('encounter_id', $encounterIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $latestByEncounterId = [];
        foreach ($records as $record) {
            $encounterId = (string) $record->encounter_id;
            if (! isset($latestByEncounterId[$encounterId])) {
                $latestByEncounterId[$encounterId] = $record->toArray();
            }
        }

        return $latestByEncounterId;
    }

    public function statusCounts(
        ?string $query,
        ?string $patientId,
        ?int $primaryClinicianUserId,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = EncounterModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);
        $this->applyFilters($queryBuilder, $query, $patientId, null, $primaryClinicianUserId, $fromDateTime, $toDateTime);

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = array_fill_keys(EncounterStatus::values(), 0);
        $counts['other'] = 0;
        $counts['total'] = 0;

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($status, $counts)) {
                $counts[$status] += $aggregate;
            } else {
                $counts['other'] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    private function applyFilters(
        Builder $queryBuilder,
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?int $primaryClinicianUserId,
        ?string $fromDateTime,
        ?string $toDateTime,
    ): void {
        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('encounter_number', 'like', $like)
                        ->orWhereHas('patient', function (Builder $patientQuery) use ($like): void {
                            $patientQuery
                                ->where('first_name', 'like', $like)
                                ->orWhere('middle_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like)
                                ->orWhere('patient_number', 'like', $like);
                        });
                });
            })
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($primaryClinicianUserId, fn (Builder $builder, int $requestedClinicianId) => $builder->where('primary_clinician_user_id', $requestedClinicianId))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('opened_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('opened_at', '<=', $endDateTime));
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        $encounterIds = array_map(static fn (EncounterModel $encounter): string => (string) $encounter->id, $paginator->items());
        $latestMedicalRecordsByEncounterId = $this->latestMedicalRecordsByEncounterId($encounterIds);

        return [
            'data' => array_map(
                static function (EncounterModel $encounter) use ($latestMedicalRecordsByEncounterId): array {
                    $data = $encounter->toArray();
                    $data['latest_medical_record'] = $latestMedicalRecordsByEncounterId[(string) $encounter->id] ?? null;

                    return $data;
                },
                $paginator->items(),
            ),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }
}
