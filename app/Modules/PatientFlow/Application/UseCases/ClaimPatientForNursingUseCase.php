<?php

namespace App\Modules\PatientFlow\Application\UseCases;

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\PatientFlow\Application\Services\RecordPatientFlowTransitionService;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The nursing counterpart to ClaimAppointmentTriageUseCase.
 *
 * Triage has had an explicit "I have picked this patient up" claim since
 * Phase 2 (triage_owner_user_id), and the board uses it to show IN_TRIAGE
 * rather than WAITING_TRIAGE. Nursing had no equivalent, which is why the
 * 2026-08-16 flow audit found nursing steps invisible: a nurse could be
 * actively working with a patient while every other workspace still showed
 * them waiting.
 *
 * Writes nursing_contact_user_id/nursing_contact_started_at transactionally —
 * those columns, not the flow log, are what every queue badge reads. The log
 * entry alongside is the history of the pickup, and is allowed to fail without
 * taking the pickup with it (see RecordPatientFlowTransitionService).
 *
 * Deliberately changes no appointment status. Nursing contact happens *inside*
 * an existing status (a patient waiting for a doctor is still waiting for that
 * doctor while a nurse works with them), so forcing a status change here would
 * corrupt the provider queue to express something the ownership column already
 * says precisely.
 */
class ClaimPatientForNursingUseCase
{
    public function __construct(
        private readonly RecordPatientFlowTransitionService $recordPatientFlowTransition,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $encounterId, ?int $actorId = null): array
    {
        $encounter = EncounterModel::query()->find($encounterId);

        if ($encounter === null) {
            throw ValidationException::withMessages([
                'encounterId' => ['This visit no longer exists.'],
            ]);
        }

        $appointmentId = $encounter->appointment_id !== null ? (string) $encounter->appointment_id : null;

        return DB::transaction(function () use ($encounter, $encounterId, $appointmentId, $actorId): array {
            $appointment = $appointmentId !== null
                ? AppointmentModel::query()->lockForUpdate()->find($appointmentId)
                : null;

            $alreadyHeldBy = $appointment?->nursing_contact_user_id;

            // Another nurse already has this patient. Reported rather than
            // thrown so the caller can show who, mirroring how the triage claim
            // and consultation ownership both surface a conflict instead of
            // silently taking over.
            if ($alreadyHeldBy !== null && $actorId !== null && (int) $alreadyHeldBy !== $actorId) {
                return [
                    'step' => PatientFlowStep::WITH_NURSE->value,
                    'stepLabel' => PatientFlowStep::WITH_NURSE->label(),
                    'recorded' => false,
                    'heldByUserId' => (int) $alreadyHeldBy,
                    'occurredAt' => optional($appointment->nursing_contact_started_at)->toISOString(),
                ];
            }

            $startedAt = now();

            if ($appointment !== null && $alreadyHeldBy === null) {
                $appointment->forceFill([
                    'nursing_contact_user_id' => $actorId,
                    'nursing_contact_started_at' => $startedAt,
                ])->save();
            }

            $event = $this->recordPatientFlowTransition->record(
                toStep: PatientFlowStep::WITH_NURSE,
                patientId: (string) $encounter->patient_id,
                appointmentId: $appointmentId,
                encounterId: $encounterId,
                actorId: $actorId,
                source: 'nursing.patient_claimed',
                facilityId: $encounter->facility_id ?? $appointment?->facility_id,
            );

            return [
                'step' => PatientFlowStep::WITH_NURSE->value,
                'stepLabel' => PatientFlowStep::WITH_NURSE->label(),
                // false means the patient was already recorded as WITH_NURSE by
                // this same nurse — a no-op, not a failure. The caller's UI
                // should look the same either way.
                'recorded' => $event !== null,
                'heldByUserId' => $actorId,
                'occurredAt' => $event['occurred_at'] ?? optional($startedAt)->toISOString(),
            ];
        });
    }
}
