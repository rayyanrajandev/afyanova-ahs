<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Domain\ValueObjects\EncounterStatus;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Payer\Infrastructure\Models\PatientInsuranceModel;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;
use App\Modules\ServiceRequest\Application\Services\NursingVisitContextResolver;
use Illuminate\Support\Facades\DB;

/**
 * Who is nursing's responsibility right now.
 *
 * Membership is every live encounter that has not yet been assessed. It is
 * matched on EncounterStatus::liveStatuses() rather than `opened` alone —
 * see that method for the incident that rule was written after.
 *
 * Extracted from NurseQueueController (2026-08-19, workspace maturity audit
 * goal G2): nursing was the one workspace running its queries from the
 * controller, and it was also the one that lost a patient.
 */
class ListNursingWorklistUseCase
{
    private const PER_PAGE_DEFAULT = 20;

    private const PER_PAGE_MAX = 100;

    public function __construct(
        private readonly NursingVisitContextResolver $contextResolver,
    ) {}

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function execute(int $perPage = self::PER_PAGE_DEFAULT, int $page = 1): array
    {
        $perPage = min(max($perPage, 1), self::PER_PAGE_MAX);
        $page = max($page, 1);

        $encounters = EncounterModel::query()
            ->select('encounters.*')
            ->whereIn('encounters.status', EncounterStatus::liveStatuses())
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('service_requests')
                    ->whereColumn('service_requests.encounter_id', 'encounters.id')
                    ->whereNotNull('service_requests.assessed_by_user_id');
            })
            ->with(['patient' => function ($query): void {
                $query->select(['id', 'patient_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'phone']);
            }])
            ->orderBy('encounters.opened_at', 'asc')
            ->paginate(perPage: $perPage, page: $page);

        $appointmentIds = $encounters->getCollection()->pluck('appointment_id')->filter()->unique()->values();
        $patientIds = $encounters->getCollection()->pluck('patient_id')->filter()->unique()->values();

        // Batch-loaded so the per-row context below costs no extra queries.
        $appointments = $appointmentIds->isNotEmpty()
            ? AppointmentModel::query()->whereIn('id', $appointmentIds)->get()->keyBy('id')
            : collect();

        $arrivalEvents = $appointmentIds->isNotEmpty()
            ? ArrivalEventModel::query()
                ->whereIn('appointment_id', $appointmentIds)
                ->orderByDesc('arrived_at')
                ->get()
                ->groupBy('appointment_id')
                ->map->first()
            : collect();

        $insuranceRecords = $patientIds->isNotEmpty()
            ? PatientInsuranceModel::query()
                ->whereIn('patient_id', $patientIds)
                ->where('status', 'active')
                ->get()
                ->groupBy('patient_id')
                ->map->first()
            : collect();

        // One query for the whole page rather than one per row.
        $vitalsByAppointment = $this->contextResolver->appointmentsWithRecordedVitals($appointmentIds);

        $data = $encounters->map(function (EncounterModel $encounter) use ($appointments, $arrivalEvents, $insuranceRecords, $vitalsByAppointment): array {
            $patient = $encounter->patient;
            $appointment = $appointments->get($encounter->appointment_id);
            $arrivalEvent = $arrivalEvents->get($encounter->appointment_id);
            $insurance = $insuranceRecords->get($encounter->patient_id);

            return [
                'id' => $encounter->id,
                'encounterNumber' => $encounter->encounter_number,
                'patientId' => $encounter->patient_id,
                'appointmentId' => $encounter->appointment_id,
                'status' => $encounter->status,
                'type' => $encounter->type,
                'openedAt' => $encounter->opened_at?->toISOString(),
                'visit' => $this->contextResolver->visit(
                    $appointment,
                    $encounter,
                    $arrivalEvent,
                    isset($vitalsByAppointment[(string) $encounter->appointment_id]),
                ),
                'readiness' => $this->contextResolver->readiness($appointment, $insurance, $arrivalEvent),
                'patient' => $patient ? [
                    'id' => $patient->id,
                    'patientNumber' => $patient->patient_number,
                    'firstName' => $patient->first_name,
                    'middleName' => $patient->middle_name,
                    'lastName' => $patient->last_name,
                    'dateOfBirth' => $patient->date_of_birth?->toISOString(),
                    'gender' => $patient->gender,
                    'phone' => $patient->phone,
                    'age' => $patient->date_of_birth ? $patient->date_of_birth->diffInYears(now()) : null,
                ] : null,
            ];
        });

        return [
            'data' => $data->all(),
            'meta' => [
                'currentPage' => $encounters->currentPage(),
                'perPage' => $encounters->perPage(),
                'total' => $encounters->total(),
                'lastPage' => $encounters->lastPage(),
            ],
        ];
    }
}
