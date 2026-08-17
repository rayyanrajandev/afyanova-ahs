<?php

namespace App\Modules\PatientFlow\Application\UseCases;

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\PatientFlow\Application\Services\RecordPatientFlowTransitionService;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ends a nursing contact and puts the patient back into the queue they were
 * in — the counterpart to ClaimPatientForNursingUseCase, mirroring
 * ReleaseAppointmentTriageClaimUseCase on the triage side.
 *
 * Clears nursing_contact_user_id/nursing_contact_started_at first, then
 * resolves the step the visit falls back to from the appointment's own
 * columns rather than remembering it from before the claim: the visit may
 * legitimately have moved on while the nurse was with the patient (a doctor
 * starting the consultation, reception cancelling it), and the appointment's
 * current state is authoritative for that.
 */
class ReleasePatientFromNursingUseCase
{
    public function __construct(
        private readonly RecordPatientFlowTransitionService $recordPatientFlowTransition,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $encounterId, ?int $actorId = null, ?string $reason = null): array
    {
        $encounter = EncounterModel::query()->find($encounterId);

        if ($encounter === null) {
            throw ValidationException::withMessages([
                'encounterId' => ['This visit no longer exists.'],
            ]);
        }

        $appointmentId = $encounter->appointment_id !== null ? (string) $encounter->appointment_id : null;

        return DB::transaction(function () use ($encounter, $encounterId, $appointmentId, $actorId, $reason): array {
            $appointment = $appointmentId !== null
                ? AppointmentModel::query()->lockForUpdate()->find($appointmentId)
                : null;

            if ($appointment !== null && $appointment->nursing_contact_user_id !== null) {
                $appointment->forceFill([
                    'nursing_contact_user_id' => null,
                    'nursing_contact_started_at' => null,
                ])->save();
            }

            // Resolved *after* clearing the claim, so it reports the queue the
            // patient is going back to rather than WITH_NURSE again.
            $toStep = PatientFlowStep::forAppointment($appointment)
                // A visit with no appointment at all (a direct encounter) has no
                // status to fall back to; WAITING_CLINICIAN is the honest default
                // there — the nurse is done, and somebody clinical still needs to
                // see the patient.
                ?? PatientFlowStep::WAITING_CLINICIAN;

            $event = $this->recordPatientFlowTransition->record(
                toStep: $toStep,
                patientId: (string) $encounter->patient_id,
                appointmentId: $appointmentId,
                encounterId: $encounterId,
                actorId: $actorId,
                source: 'nursing.patient_released',
                reason: $reason,
                facilityId: $encounter->facility_id ?? $appointment?->facility_id,
            );

            return [
                'step' => $toStep->value,
                'stepLabel' => $toStep->label(),
                'recorded' => $event !== null,
                'occurredAt' => $event['occurred_at'] ?? null,
            ];
        });
    }
}
