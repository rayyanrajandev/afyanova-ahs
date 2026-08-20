<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Domain\ValueObjects\EncounterStatus;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Payer\Infrastructure\Models\PatientInsuranceModel;
use App\Modules\ServiceRequest\Application\Services\NursingVisitContextResolver;
use App\Modules\ServiceRequest\Application\Services\VisitNoteLogService;

/**
 * The visit a patient is currently on, for the nursing patient header.
 *
 * Selecting a patient from the Patients tab is an unscoped lookup — it says
 * nothing about whether that patient has a live visit. This answers that
 * separately, which is why the header can show "Walk-in OPD · In Triage" for a
 * patient reached by search rather than from the worklist.
 */
class GetActiveVisitContextUseCase
{
    public function __construct(
        private readonly NursingVisitContextResolver $contextResolver,
        private readonly VisitNoteLogService $visitNotes,
    ) {}

    /**
     * @return array<string, mixed>|null Null when the patient is not on a live visit.
     */
    public function execute(string $patientId): ?array
    {
        $encounter = EncounterModel::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', EncounterStatus::liveStatuses())
            ->orderByDesc('opened_at')
            ->first();

        if ($encounter === null) {
            return null;
        }

        $appointment = $encounter->appointment_id !== null
            ? AppointmentModel::query()->find($encounter->appointment_id)
            : null;

        $arrivalEvent = $encounter->appointment_id !== null
            ? $this->visitNotes->latestArrivalEvent((string) $encounter->appointment_id)
            : null;

        $insurance = PatientInsuranceModel::query()
            ->where('patient_id', $patientId)
            ->where('status', 'active')
            ->first();

        $hasRecordedVitals = $encounter->appointment_id !== null
            && $this->contextResolver->appointmentsWithRecordedVitals([(string) $encounter->appointment_id]) !== [];

        return [
            'encounterId' => $encounter->id,
            'visit' => $this->contextResolver->visit($appointment, $encounter, $arrivalEvent, $hasRecordedVitals),
            'readiness' => $this->contextResolver->readiness($appointment, $insurance, $arrivalEvent),
        ];
    }
}
