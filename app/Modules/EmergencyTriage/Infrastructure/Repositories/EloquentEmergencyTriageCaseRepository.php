<?php

namespace App\Modules\EmergencyTriage\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;
use App\Modules\EmergencyTriage\Infrastructure\Models\EmergencyTriageCaseModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentEmergencyTriageCaseRepository implements EmergencyTriageCaseRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $case = new EmergencyTriageCaseModel();
        $case->fill($attributes);
        $case->save();

        return $case->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = EmergencyTriageCaseModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $case = $query->find($id);

        return $case?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = EmergencyTriageCaseModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $case = $query->find($id);
        if (! $case) {
            return null;
        }

        $case->fill($attributes);
        $case->save();

        return $case->toArray();
    }

    public function existsByCaseNumber(string $caseNumber): bool
    {
        return EmergencyTriageCaseModel::query()
            ->where('case_number', $caseNumber)
            ->exists();
    }

    public function existsByAppointmentId(string $appointmentId): bool
    {
        return EmergencyTriageCaseModel::query()
            ->where('appointment_id', $appointmentId)
            ->exists();
    }

    public function findByAppointmentId(string $appointmentId): ?array
    {
        $case = EmergencyTriageCaseModel::query()
            ->where('appointment_id', $appointmentId)
            ->first();

        return $case?->toArray();
    }

    public function findActiveForPatient(string $patientId): ?array
    {
        $query = EmergencyTriageCaseModel::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', ['waiting', 'triaged', 'in_treatment']);
        $this->applyPlatformScopeIfEnabled($query);

        $case = $query->orderBy('arrived_at')->first();

        return $case?->toArray();
    }

    public function search(
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?string $triageLevel,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['case_number', 'arrived_at', 'triaged_at', 'status', 'triage_level', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'arrived_at';

        $queryBuilder = EmergencyTriageCaseModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $builder->where(fn (Builder $nestedQuery) => $this->applyCaseInsensitiveSearch($nestedQuery, $searchTerm)))
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($triageLevel, fn (Builder $builder, string $requestedTriageLevel) => $builder->where('triage_level', $requestedTriageLevel))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('arrived_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('arrived_at', '<=', $endDateTime))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCounts(
        ?string $query,
        ?string $patientId,
        ?string $triageLevel,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = EmergencyTriageCaseModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $builder->where(fn (Builder $nestedQuery) => $this->applyCaseInsensitiveSearch($nestedQuery, $searchTerm)))
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($triageLevel, fn (Builder $builder, string $requestedTriageLevel) => $builder->where('triage_level', $requestedTriageLevel))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('arrived_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('arrived_at', '<=', $endDateTime));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'waiting' => 0,
            'triaged' => 0,
            'in_treatment' => 0,
            'admitted' => 0,
            'discharged' => 0,
            'cancelled' => 0,
            'other' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($status, $counts) && $status !== 'other' && $status !== 'total') {
                $counts[$status] += $aggregate;
            } else {
                $counts['other'] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    /**
     * Postgres's LIKE is case-sensitive (unlike SQLite, which the test
     * suite runs on) — a plain where('chief_complaint', 'like', ...)
     * silently missed mixed-case matches in production. Same fix and
     * reasoning as EloquentFacilityResourceRepository::
     * applyCaseInsensitiveSearch() (Reception/Emergency/Admission/
     * Bed-Management audit follow-through).
     */
    private function applyCaseInsensitiveSearch(Builder $query, string $searchTerm): Builder
    {
        $like = '%'.strtolower($searchTerm).'%';

        return $query
            ->whereRaw('LOWER(case_number) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(triage_level, \'\')) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(chief_complaint, \'\')) LIKE ?', [$like])
            ->orWhereRaw('LOWER(COALESCE(vitals_summary, \'\')) LIKE ?', [$like]);
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
        return [
            'data' => array_map(
                static fn (EmergencyTriageCaseModel $case): array => $case->toArray(),
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
