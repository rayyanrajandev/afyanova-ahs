<?php

namespace App\Modules\Reception\Application\UseCases;

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;

/**
 * Backs triage/Queue.vue's "Completed today" tab — the list counterpart to
 * GetTriageQueueStatusCountsUseCase's `completed` count, which this query
 * deliberately mirrors exactly (whereNotNull('triaged_at')->where('triaged_at',
 * '>=', today), no status filter) so the tab's total always matches the KPI
 * card above it. An appointment leaves waiting_triage the instant triage is
 * recorded, so these rows are no longer part of GetReceptionQueueUseCase's
 * live "stage" population — they need their own query, not a filter over
 * that one. `status` on each entry is the appointment's *current* status
 * (waiting_provider, in_consultation, completed, or — rarely — cancelled if
 * the visit was closed out after triage), so the tab doubles as "where did
 * today's triaged patients end up," not just a triage-completion log.
 */
class GetTriageCompletedTodayUseCase
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $today = now()->startOfDay();

        $paginator = AppointmentModel::query()
            ->whereNotNull('triaged_at')
            ->where('triaged_at', '>=', $today)
            ->orderByDesc('triaged_at')
            ->paginate(perPage: $perPage, columns: ['*'], pageName: 'page', page: $page);

        $appointments = collect($paginator->items());

        if ($appointments->isEmpty()) {
            return [
                'data' => [],
                'meta' => [
                    'currentPage' => $paginator->currentPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'lastPage' => $paginator->lastPage(),
                ],
            ];
        }

        // Batched, not per-row — same reasoning as
        // GetReceptionQueueUseCase::buildEntries().
        $patientsById = PatientModel::query()
            ->whereIn('id', $appointments->pluck('patient_id')->unique())
            ->get(['id', 'patient_number', 'first_name', 'middle_name', 'last_name'])
            ->keyBy('id');

        $data = $appointments->map(function (AppointmentModel $appointment) use ($patientsById): array {
            $patient = $patientsById->get($appointment->patient_id);
            $patientName = $patient !== null
                ? implode(' ', array_filter([
                    $patient->first_name,
                    $patient->middle_name,
                    $patient->last_name,
                ], static fn (?string $part): bool => $part !== null && trim($part) !== ''))
                : null;

            return [
                'appointmentId' => $appointment->id,
                'appointmentNumber' => $appointment->appointment_number,
                'status' => $appointment->status,
                'patientId' => $appointment->patient_id,
                'patientName' => $patientName !== '' ? $patientName : null,
                'patientNumber' => $patient?->patient_number,
                'department' => $appointment->department,
                'triagedAt' => $appointment->triaged_at,
                'triageOwnerUserId' => $appointment->triage_owner_user_id,
            ];
        })->values()->all();

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
}
