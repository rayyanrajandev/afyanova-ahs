<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\Appointment\Application\UseCases\UpdateAppointmentStatusUseCase;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Domain\ValueObjects\EncounterStatus;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\PatientFlow\Domain\Events\PatientFlowBoardUpdated;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use App\Modules\Reception\Domain\Events\PatientReturnedToReception;
use App\Modules\ServiceRequest\Application\Services\VisitNoteLogService;
use Illuminate\Support\Facades\Log;

/**
 * Hand a patient back to the front desk.
 *
 * Five things have to happen together and none of them is nursing's alone: the
 * visit returns to Reception's queue, the nursing encounter is closed out, the
 * reason is written to the visit log, and both boards are told. Doing this from
 * a controller meant the sequence was invisible to every other caller and
 * untestable without HTTP.
 */
class ReturnPatientToReceptionUseCase
{
    public function __construct(
        private readonly UpdateAppointmentStatusUseCase $updateAppointmentStatus,
        private readonly VisitNoteLogService $visitNotes,
    ) {}

    /**
     * The id may be an appointment or — for a direct walk-in with no
     * appointment record — an encounter.
     *
     * @return array{type: 'appointment'|'encounter'|'not_found', appointment?: array<string, mixed>, encounter?: array<string, mixed>}
     */
    public function execute(
        string $appointmentOrEncounterId,
        ?string $reason,
        ?int $actorId,
        ?string $actorName,
        ?string $actorFacilityId,
    ): array {
        $rawReason = trim((string) $reason);
        $formattedReason = $rawReason !== ''
            ? 'Returned to Reception: '.$rawReason
            : 'Returned to Reception by Nursing for administrative verification';

        $appointment = AppointmentModel::query()->find($appointmentOrEncounterId);

        if ($appointment === null) {
            $encounter = EncounterModel::query()->find($appointmentOrEncounterId);

            if ($encounter === null) {
                return ['type' => 'not_found'];
            }

            if ($encounter->appointment_id === null) {
                // A direct walk-in: there is no appointment to return, so
                // closing the encounter is the whole of the hand-back.
                $this->closeEncounterUnlessFinalised($encounter);

                return ['type' => 'encounter', 'encounter' => [
                    'id' => $encounter->id,
                    'status' => $encounter->status,
                    'reason' => $formattedReason,
                ]];
            }

            $appointment = AppointmentModel::query()->find($encounter->appointment_id);

            if ($appointment === null) {
                return ['type' => 'not_found'];
            }
        }

        // 1. Back into Reception's queue.
        //
        //    Routed through UpdateAppointmentStatusUseCase rather than a raw
        //    Eloquent update: a direct write skips the transition guard, writes
        //    no audit row and records no flow event, which left
        //    `returned_to_reception` unreachable and the hand-back invisible on
        //    the Activity timeline.
        $this->updateAppointmentStatus->execute(
            id: (string) $appointment->id,
            status: AppointmentStatus::WAITING_TRIAGE->value,
            reason: $formattedReason,
            actorId: $actorId,
            statusAttributes: [
                // Handing the patient back necessarily ends any nursing
                // contact — without clearing this the visit keeps reading
                // "With Nurse" in every queue after the nurse let them go.
                'nursing_contact_user_id' => null,
                'nursing_contact_started_at' => null,
            ],
            flowSource: 'nursing.returned_to_reception',
            flowStepOverride: PatientFlowStep::RETURNED_TO_RECEPTION,
        );

        $appointment->refresh();

        // 2. Close the live nursing encounter.
        $encounter = EncounterModel::query()
            ->where('appointment_id', $appointment->id)
            ->whereIn('status', EncounterStatus::liveStatuses())
            ->first();

        if ($encounter !== null) {
            $this->closeEncounterUnlessFinalised($encounter);
        }

        // 3. Record why, on the visit log the desk will read.
        $this->visitNotes->append(
            (string) $appointment->id,
            $formattedReason,
            $actorName !== null && trim($actorName) !== '' ? $actorName : 'Nurse',
        );

        // 4. Tell Reception and the flow board.
        $patient = PatientModel::query()->find($appointment->patient_id);
        $patientName = $patient !== null
            ? trim("{$patient->first_name} {$patient->last_name}")
            : '';
        $facilityId = $actorFacilityId ?? $appointment->facility_id ?? null;

        event(new PatientReturnedToReception(
            appointmentId: $appointment->id,
            patientId: $appointment->patient_id,
            patientName: $patientName !== '' ? $patientName : 'Patient',
            reason: $rawReason !== '' ? $rawReason : 'Administrative verification',
            facilityId: $facilityId,
        ));

        event(new PatientFlowBoardUpdated($facilityId));

        return ['type' => 'appointment', 'appointment' => $appointment->toArray()];
    }

    /**
     * Finalised documentation is never discarded here. A note submitted for
     * signature is completed clinical work, and a nurse handing a patient back
     * to reception is not authority to throw it away — so the encounter is left
     * standing and the conflict is recorded, rather than resolved silently in
     * either direction.
     */
    private function closeEncounterUnlessFinalised(EncounterModel $encounter): void
    {
        $status = EncounterStatus::tryFrom((string) $encounter->status);

        if ($status?->carriesFinalisedDocumentation() === true) {
            Log::warning('Patient returned to reception while their encounter carried finalised documentation.', [
                'encounter_id' => (string) $encounter->id,
                'appointment_id' => $encounter->appointment_id,
                'encounter_status' => (string) $encounter->status,
            ]);

            return;
        }

        $encounter->update(['status' => EncounterStatus::CANCELLED->value]);
    }
}
