<?php

namespace App\Modules\PatientFlow\Infrastructure\Repositories;

use App\Modules\PatientFlow\Domain\Repositories\PatientFlowEventRepositoryInterface;
use App\Modules\PatientFlow\Infrastructure\Models\PatientFlowEventModel;
use Illuminate\Database\Eloquent\Builder;

class EloquentPatientFlowEventRepository implements PatientFlowEventRepositoryInterface
{
    public function append(array $attributes): array
    {
        $model = new PatientFlowEventModel();
        $model->fill($attributes);
        $model->save();

        return $model->toArray();
    }

    public function latestForVisit(?string $appointmentId, ?string $serviceRequestId): ?array
    {
        $query = PatientFlowEventModel::query();

        if (! $this->applyVisitScope($query, $appointmentId, $serviceRequestId)) {
            return null;
        }

        return $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first()
            ?->toArray();
    }

    public function listForPatient(string $patientId, int $page, int $perPage): array
    {
        $paginator = PatientFlowEventModel::query()
            ->where('patient_id', $patientId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);

        return [
            'data' => array_map(
                static fn (PatientFlowEventModel $event): array => $event->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }

    public function listForVisit(?string $appointmentId, ?string $serviceRequestId, int $limit): array
    {
        $query = PatientFlowEventModel::query();

        if (! $this->applyVisitScope($query, $appointmentId, $serviceRequestId)) {
            return [];
        }

        return $query
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(static fn (PatientFlowEventModel $event): array => $event->toArray())
            ->all();
    }

    public function currentStepEnteredAtByAppointmentIds(array $appointmentIds): array
    {
        if ($appointmentIds === []) {
            return [];
        }

        // The latest event for a visit *is* the one that put it in its current
        // step, so MAX(occurred_at) is the answer directly — no need to load
        // whole rows and discard all but one per appointment.
        return PatientFlowEventModel::query()
            ->selectRaw('appointment_id, MAX(occurred_at) as entered_at')
            ->whereIn('appointment_id', $appointmentIds)
            ->groupBy('appointment_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->appointment_id => optional(
                    $row->entered_at instanceof \DateTimeInterface
                        ? \Illuminate\Support\Carbon::instance($row->entered_at)
                        : \Illuminate\Support\Carbon::parse((string) $row->entered_at)
                )->toISOString(),
            ])
            ->all();
    }

    /**
     * A visit is identified by an appointment, a service request, or both.
     * Returns false when neither was supplied, so callers can short-circuit
     * rather than run an unscoped query that would return another visit's log.
     *
     * @param  Builder<PatientFlowEventModel>  $query
     */
    private function applyVisitScope(Builder $query, ?string $appointmentId, ?string $serviceRequestId): bool
    {
        if ($appointmentId === null && $serviceRequestId === null) {
            return false;
        }

        $query->where(function (Builder $scoped) use ($appointmentId, $serviceRequestId): void {
            if ($appointmentId !== null) {
                $scoped->orWhere('appointment_id', $appointmentId);
            }
            if ($serviceRequestId !== null) {
                $scoped->orWhere('service_request_id', $serviceRequestId);
            }
        });

        return true;
    }
}
