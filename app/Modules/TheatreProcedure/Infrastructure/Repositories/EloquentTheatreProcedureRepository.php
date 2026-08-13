<?php

namespace App\Modules\TheatreProcedure\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use App\Support\ClinicalOrders\ClinicalOrderEntryState;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentTheatreProcedureRepository implements TheatreProcedureRepositoryInterface
{
    private const PATIENT_RELATION_COLUMNS = [
        'id',
        'patient_number',
        'first_name',
        'middle_name',
        'last_name',
    ];

    private const THEATRE_ROOM_RELATION_COLUMNS = [
        'id',
        'code',
        'name',
        'service_point_type',
        'location',
        'status',
    ];

    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $procedure = new TheatreProcedureModel();
        $procedure->fill($attributes);
        $procedure->save();

        return $this->loadProcedureWithRelations((string) $procedure->id)?->toArray() ?? $procedure->toArray();
    }

    public function findById(string $id): ?array
    {
        $procedure = $this->loadProcedureWithRelations($id);

        return $procedure?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = TheatreProcedureModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $procedure = $query->find($id);
        if (! $procedure) {
            return null;
        }

        $procedure->fill($attributes);
        $procedure->save();

        return $this->loadProcedureWithRelations($id)?->toArray() ?? $procedure->toArray();
    }

    public function delete(string $id): bool
    {
        $query = TheatreProcedureModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $procedure = $query->find($id);
        if (! $procedure) {
            return false;
        }

        return (bool) $procedure->delete();
    }

    public function existsByProcedureNumber(string $procedureNumber): bool
    {
        return TheatreProcedureModel::query()
            ->where('procedure_number', $procedureNumber)
            ->exists();
    }

    public function search(
        ?string $query,
        ?string $patientId,
        ?string $appointmentId,
        ?string $admissionId,
        ?string $status,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['procedure_number', 'scheduled_at', 'status', 'procedure_type', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'scheduled_at';

        $queryBuilder = TheatreProcedureModel::query()
            ->with([
                'patient:'.implode(',', self::PATIENT_RELATION_COLUMNS),
                'theatreRoomServicePoint:'.implode(',', self::THEATRE_ROOM_RELATION_COLUMNS),
            ]);
        $this->applyPlatformScopeIfEnabled($queryBuilder);
        $this->applyActiveEntryStateScope($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('procedure_number', 'like', $like)
                        ->orWhere('procedure_type', 'like', $like)
                        ->orWhere('procedure_name', 'like', $like)
                        ->orWhere('theatre_room_name', 'like', $like)
                        ->orWhere('status_reason', 'like', $like)
                        ->orWhere('notes', 'like', $like);
                });
            })
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($appointmentId, fn (Builder $builder, string $requestedAppointmentId) => $builder->where('appointment_id', $requestedAppointmentId))
            ->when($admissionId, fn (Builder $builder, string $requestedAdmissionId) => $builder->where('admission_id', $requestedAdmissionId))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('scheduled_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('scheduled_at', '<=', $endDateTime))
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
        ?string $appointmentId,
        ?string $admissionId,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = TheatreProcedureModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);
        $this->applyActiveEntryStateScope($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('procedure_number', 'like', $like)
                        ->orWhere('procedure_type', 'like', $like)
                        ->orWhere('procedure_name', 'like', $like)
                        ->orWhere('theatre_room_name', 'like', $like)
                        ->orWhere('status_reason', 'like', $like)
                        ->orWhere('notes', 'like', $like);
                });
            })
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($appointmentId, fn (Builder $builder, string $requestedAppointmentId) => $builder->where('appointment_id', $requestedAppointmentId))
            ->when($admissionId, fn (Builder $builder, string $requestedAdmissionId) => $builder->where('admission_id', $requestedAdmissionId))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('scheduled_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('scheduled_at', '<=', $endDateTime));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'planned' => 0,
            'in_preop' => 0,
            'in_progress' => 0,
            'completed' => 0,
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

    private function applyActiveEntryStateScope(Builder $query): void
    {
        $query->where('entry_state', ClinicalOrderEntryState::ACTIVE->value);
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (TheatreProcedureModel $procedure): array => $procedure->toArray(),
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

    private function loadProcedureWithRelations(string $id): ?TheatreProcedureModel
    {
        $query = TheatreProcedureModel::query()
            ->with([
                'patient:'.implode(',', self::PATIENT_RELATION_COLUMNS),
                'theatreRoomServicePoint:'.implode(',', self::THEATRE_ROOM_RELATION_COLUMNS),
            ]);
        $this->applyPlatformScopeIfEnabled($query);

        return $query->find($id);
    }
}
