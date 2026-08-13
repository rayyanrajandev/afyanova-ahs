<?php

namespace App\Modules\Appointment\Infrastructure\Repositories;

use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class EloquentAppointmentRepository implements AppointmentRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $appointment = new AppointmentModel();
        $appointment->fill($this->persistableAttributes($attributes));
        $appointment->save();

        return $appointment->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = AppointmentModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $appointment = $query->find($id);

        return $appointment?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = AppointmentModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $appointment = $query->find($id);
        if (! $appointment) {
            return null;
        }

        $appointment->fill($this->persistableAttributes($attributes));
        $appointment->save();

        return $appointment->toArray();
    }

    public function existsByAppointmentNumber(string $appointmentNumber): bool
    {
        return AppointmentModel::query()
            ->where('appointment_number', $appointmentNumber)
            ->exists();
    }

    public function findActiveForPatientOnDate(
        string $patientId,
        string $scheduledDate,
        ?string $excludeAppointmentId = null,
    ): ?array {
        $query = AppointmentModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $query
            ->where('patient_id', $patientId)
            ->whereDate('scheduled_at', $scheduledDate)
            ->whereIn('status', ['scheduled', 'waiting_triage', 'waiting_provider', 'in_consultation'])
            ->when(
                $excludeAppointmentId,
                fn (Builder $builder, string $appointmentId) => $builder->where('id', '!=', $appointmentId),
            )
            ->orderBy('scheduled_at');

        return $query->first()?->toArray();
    }

    public function findActiveForPatient(
        string $patientId,
        ?string $excludeAppointmentId = null,
    ): ?array {
        $query = AppointmentModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $query
            ->where('patient_id', $patientId)
            // Deliberately excludes 'scheduled' (unlike
            // findActiveForPatientOnDate()'s whitelist above): this method
            // answers "has the patient already arrived and not yet been
            // resolved," for blocking a duplicate walk-in/emergency
            // check-in — a merely-scheduled future booking (even later
            // today) isn't an arrival in progress and must not block an
            // emergency walk-in from being checked in right now.
            // findActiveForPatientOnDate() correctly keeps 'scheduled' in
            // its own whitelist because its job (same-day double-booking
            // prevention) is a different question.
            ->whereIn('status', ['waiting_triage', 'waiting_provider', 'in_consultation'])
            ->when(
                $excludeAppointmentId,
                fn (Builder $builder, string $appointmentId) => $builder->where('id', '!=', $appointmentId),
            )
            ->orderBy('scheduled_at');

        return $query->first()?->toArray();
    }

    public function findOtherUpcomingForPatient(
        string $patientId,
        string $excludeAppointmentId,
    ): ?array {
        $query = AppointmentModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $query
            ->where('patient_id', $patientId)
            ->where('id', '!=', $excludeAppointmentId)
            // 'scheduled' included (unlike findActiveForPatient() above) —
            // this is exactly the case it's meant to catch: an existing
            // future booking the patient hasn't arrived for yet. No date
            // scoping either, since the whole point is surfacing conflicts
            // findActiveForPatientOnDate()'s same-day-only hard block
            // doesn't catch.
            ->whereIn('status', ['scheduled', 'waiting_triage', 'waiting_provider', 'in_consultation'])
            ->orderBy('scheduled_at');

        return $query->first()?->toArray();
    }

    public function findActiveForClinicianInWindow(
        int $clinicianUserId,
        string $windowStart,
        string $windowEnd,
        ?string $excludeAppointmentId = null,
    ): array {
        $query = AppointmentModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $query
            ->where('clinician_user_id', $clinicianUserId)
            ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
            ->whereIn('status', ['scheduled', 'waiting_triage', 'waiting_provider', 'in_consultation'])
            ->when(
                $excludeAppointmentId,
                fn (Builder $builder, string $appointmentId) => $builder->where('id', '!=', $appointmentId),
            )
            ->orderBy('scheduled_at');

        return $query->get()->map(fn (AppointmentModel $appointment): array => $appointment->toArray())->all();
    }

    public function search(
        ?string $query,
        ?string $patientId,
        ?int $clinicianUserId,
        ?string $department,
        bool $unassignedClinicianOnly,
        ?string $status,
        ?string $triageCategory,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['appointment_number', 'scheduled_at', 'checked_in_at', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'scheduled_at';

        $queryBuilder = AppointmentModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applyAppointmentTextSearch($builder, $searchTerm))
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($clinicianUserId, fn (Builder $builder, int $requestedClinicianUserId) => $builder->where('clinician_user_id', $requestedClinicianUserId))
            ->when($department, fn (Builder $builder, string $requestedDepartment) => $this->applyDepartmentFilter($builder, $requestedDepartment))
            ->when($unassignedClinicianOnly, fn (Builder $builder) => $builder->whereNull('clinician_user_id'))
            ->when($status, function (Builder $builder, string $requestedStatus): void {
                if ($requestedStatus === 'exceptions') {
                    $builder->whereIn('status', ['cancelled', 'no_show']);

                    return;
                }

                $builder->where('status', $requestedStatus);
            })
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('scheduled_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('scheduled_at', '<=', $endDateTime))
            ->when($triageCategory, fn (Builder $builder, string $cat) => $builder->where('triage_category', strtoupper($cat)))
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
        ?int $clinicianUserId,
        ?string $department,
        bool $unassignedClinicianOnly,
        ?string $status,
        ?string $triageCategory,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = AppointmentModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applyAppointmentTextSearch($builder, $searchTerm))
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($clinicianUserId, fn (Builder $builder, int $requestedClinicianUserId) => $builder->where('clinician_user_id', $requestedClinicianUserId))
            ->when($department, fn (Builder $builder, string $requestedDepartment) => $this->applyDepartmentFilter($builder, $requestedDepartment))
            ->when($unassignedClinicianOnly, fn (Builder $builder) => $builder->whereNull('clinician_user_id'))
            ->when($status, function (Builder $builder, string $requestedStatus): void {
                if ($requestedStatus === 'exceptions') {
                    $builder->whereIn('status', ['cancelled', 'no_show']);

                    return;
                }

                $builder->where('status', $requestedStatus);
            })
            ->when($triageCategory, fn (Builder $builder, string $cat) => $builder->where('triage_category', strtoupper($cat)))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('scheduled_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('scheduled_at', '<=', $endDateTime));

        // Clone the scoped+filtered builder before applying groupBy so we can
        // run separate aggregations without reapplying all the filter logic.
        $baseQuery = clone $queryBuilder;

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'scheduled' => 0,
            'waiting_triage' => 0,
            'waiting_provider' => 0,
            'in_consultation' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'no_show' => 0,
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

        // checked_in is the frontend-facing alias for waiting_triage.
        $counts['checked_in'] = $counts['waiting_triage'];

        // Walk-in count: appointments that arrived without a prior booking.
        $counts['walk_in'] = (int) (clone $baseQuery)
            ->where('appointment_type', 'walk_in')
            ->count();

        // Triage category breakdown for emergency/triage dashboards.
        $triageCategoryRows = (clone $baseQuery)
            ->whereNotNull('triage_category')
            ->selectRaw('triage_category, COUNT(*) as aggregate')
            ->groupBy('triage_category')
            ->get();

        $triageCounts = ['P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0, 'P5' => 0];
        foreach ($triageCategoryRows as $triageRow) {
            $cat = strtoupper((string) $triageRow->triage_category);
            if (array_key_exists($cat, $triageCounts)) {
                $triageCounts[$cat] = (int) $triageRow->aggregate;
            }
        }
        $counts['triage_categories'] = $triageCounts;

        return $counts;
    }

    private function applyDepartmentFilter(Builder $query, string $requestedDepartment): void
    {
        $normalized = strtolower(trim($requestedDepartment));

        if ($normalized === '') {
            return;
        }

        $query->whereRaw("LOWER(TRIM(COALESCE(department, ''))) = ?", [$normalized]);
    }

    private function applyAppointmentTextSearch(Builder $queryBuilder, string $searchTerm): void
    {
        $normalizedSearchTerm = mb_strtolower(trim($searchTerm));
        if ($normalizedSearchTerm === '') {
            return;
        }

        $like = '%'.$normalizedSearchTerm.'%';
        $trimmedSearchTerm = trim($searchTerm);

        $patientIdQuery = PatientModel::query()->select('id');
        if ($this->isPlatformScopingEnabled()) {
            $this->platformScopeQueryApplier->apply(
                $patientIdQuery,
                tenantColumn: 'tenant_id',
                facilityColumn: null,
            );
        }

        $patientIdQuery->where(function (Builder $patientQuery) use ($like): void {
            $patientQuery
                ->whereRaw('LOWER(patient_number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(first_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(middle_name, \'\')) LIKE ?', [$like])
                ->orWhereRaw("LOWER(concat(first_name, ' ', last_name)) LIKE ?", [$like])
                ->orWhereRaw("LOWER(concat(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name)) LIKE ?", [$like])
                ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(national_id, \'\')) LIKE ?', [$like]);
        });

        $queryBuilder->where(function (Builder $nestedQuery) use ($like, $trimmedSearchTerm, $patientIdQuery): void {
            $nestedQuery
                ->whereRaw('LOWER(appointment_number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(reason, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(department, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(triage_vitals_summary, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(triage_notes, \'\')) LIKE ?', [$like])
                ->orWhereIn('patient_id', $patientIdQuery);

            if (Str::isUuid($trimmedSearchTerm)) {
                $nestedQuery->orWhere('id', $trimmedSearchTerm);
            }
        });
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

    /**
     * Keep appointment writes tolerant of facility databases that are one
     * migration behind the application during staged deployments.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function persistableAttributes(array $attributes): array
    {
        try {
            $columns = Schema::getColumnListing((new AppointmentModel())->getTable());
        } catch (Throwable) {
            return $attributes;
        }

        if ($columns === []) {
            return $attributes;
        }

        return array_intersect_key($attributes, array_flip($columns));
    }

    public function findLastCompletedForPatientWithinDays(
        string $patientId,
        ?string $facilityId,
        string $scheduledAt,
        int $withinDays,
    ): ?array {
        if ($withinDays <= 0) {
            return null;
        }

        $referenceDate = \Illuminate\Support\Carbon::parse($scheduledAt);
        $windowStart = $referenceDate->copy()->subDays($withinDays)->startOfDay();

        $query = AppointmentModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $appointment = $query
            ->where('patient_id', $patientId)
            ->where('status', 'completed')
            ->when(
                $facilityId !== null && trim($facilityId) !== '',
                fn (Builder $builder): Builder => $builder->where('facility_id', $facilityId),
                fn (Builder $builder): Builder => $builder->whereNull('facility_id'),
            )
            ->whereBetween('scheduled_at', [$windowStart->toDateTimeString(), $referenceDate->toDateTimeString()])
            ->orderByDesc('scheduled_at')
            ->first();

        return $appointment?->toArray();
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (AppointmentModel $appointment): array => $appointment->toArray(),
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
