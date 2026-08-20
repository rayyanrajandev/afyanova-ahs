<?php

namespace App\Modules\ServiceRequest\Application\Services;

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use App\Modules\PatientVitals\Infrastructure\Models\PatientVitalSetModel;
use App\Modules\Payer\Infrastructure\Models\PatientInsuranceModel;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;

/**
 * Where a patient is in their visit, and how ready they are administratively —
 * assembled once for the two nursing screens that ask.
 *
 * The worklist and the patient header were building these same two payloads
 * from two copies of the same code inside NurseQueueController. Keeping them
 * together matters because they must agree: a nurse comparing the row they
 * clicked with the header that opens should not see two different stages.
 */
class NursingVisitContextResolver
{
    /**
     * Derive the fine-grained visit stage from the appointment's workflow
     * status, so the nursing UI can show where the patient is in their journey.
     *
     * Returns null when the encounter has no linked appointment (a
     * direct-service or admission-driven encounter).
     */
    public function stage(?AppointmentModel $appointment, ?EncounterModel $encounter = null): ?string
    {
        if ($encounter !== null && ($encounter->admission_id !== null || $encounter->type === 'inpatient')) {
            return 'admitted_inpatient';
        }

        if ($appointment === null) {
            return null;
        }

        // Delegated to PatientFlowStep (2026-08-16 flow audit): this was one of
        // three near-identical copies of the same mapping, none of which knew
        // about nursing pickup — so a nurse actively with a patient still read
        // as "waiting" on the very queue that nurse was working from.
        return PatientFlowStep::forAppointment($appointment)?->value
            ?? $appointment->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function visit(
        ?AppointmentModel $appointment,
        EncounterModel $encounter,
        ?ArrivalEventModel $arrivalEvent,
        bool $hasRecordedVitals = false,
    ): array {
        return [
            'appointmentStatus' => $appointment?->status,
            'stage' => $this->stage($appointment, $encounter),
            'arrivalMode' => $arrivalEvent?->arrival_mode,
            'visitCategory' => $encounter->visit_category,
            'encounterType' => $encounter->type,
            'isAdmitted' => $encounter->admission_id !== null,
            // Whether observations have been taken on *this* visit.
            //
            // Nursing used to infer this from the appointment's status — anything
            // past waiting_triage meant "vitals done", because recording them is
            // what advances a visit. The prepaid gate broke that inference: an
            // unpaid visit does not advance when vitals are recorded, so the
            // header kept offering "Record Vitals" to a nurse who had just taken
            // them. Answered from the record instead, scoped to the appointment
            // so a previous visit's observations can never satisfy this one.
            'hasRecordedVitals' => $hasRecordedVitals,
        ];
    }

    /**
     * Which of these appointments already carry observations.
     *
     * Batched deliberately: the worklist calls visit() once per row, and a
     * per-row exists() would be an N+1 on a screen a nurse reloads constantly.
     *
     * @param  iterable<int, string>  $appointmentIds
     * @return array<string, true>
     */
    public function appointmentsWithRecordedVitals(iterable $appointmentIds): array
    {
        $ids = array_values(array_filter(array_map('strval', iterator_to_array($appointmentIds, false))));

        if ($ids === []) {
            return [];
        }

        return PatientVitalSetModel::query()
            ->whereIn('appointment_id', $ids)
            ->where('entry_state', 'active')
            ->distinct()
            ->pluck('appointment_id')
            ->flip()
            ->map(static fn (): bool => true)
            ->all();
    }

    /**
     * Reception-to-nursing administrative readiness: what the desk verified
     * before the patient walked through.
     *
     * @return array<string, mixed>
     */
    public function readiness(
        ?AppointmentModel $appointment,
        ?PatientInsuranceModel $insurance,
        ?ArrivalEventModel $arrivalEvent,
    ): array {
        return [
            'coverageType' => $appointment?->financial_coverage_type,
            'insuranceVerified' => $insurance === null
                ? null
                : $insurance->verification_status === 'verified',
            'insuranceProvider' => $insurance?->insurance_provider,
            'verificationNotes' => $arrivalEvent?->verification_notes,
        ];
    }
}
