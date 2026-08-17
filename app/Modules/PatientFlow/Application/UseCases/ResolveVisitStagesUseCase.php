<?php

namespace App\Modules\PatientFlow\Application\UseCases;

use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;

/**
 * The one place that answers "where is this visit right now?" for a caller
 * holding an appointment.
 *
 * PatientFlowStep::forAppointment() reads only the appointment's own columns —
 * status plus the triage/consultation/nursing ownership fields. It cannot see
 * open diagnostic orders, and that blind spot showed: a doctor who ordered a lab
 * test during a consultation left the patient reading "With Doctor" on the
 * profile badge and the clinician queue while the patient-flow board, which does
 * consult ResolveConsultationDiagnosticStepsUseCase, correctly read "Waiting for
 * Lab" for the same patient at the same moment.
 *
 * `with_clinician` is an active-contact step: it asserts a named doctor is
 * physically with this patient. While they are standing in the lab that is false,
 * and it costs more than a wrong label — the doctor's room reads occupied, so the
 * queue will not route them the next patient, and a lab backlog stays invisible
 * because the board blames nobody for the wait.
 *
 * The precedence is deliberate and matches deriveAppointmentStep():
 *
 * 1. Someone physically with the patient wins. forAppointment() already returns
 *    with_nurse or with_clinician for that, and a nurse in the room beats where
 *    the visit is queued.
 * 2. Otherwise an open diagnostic order wins — that is where the patient
 *    actually is.
 * 3. Otherwise the appointment's own step stands.
 *
 * Rule 1 has one exception, and it is the case this class was written for: a
 * visit that is in_consultation *with an open order* is not a patient in a room.
 * The consultation stays open and the doctor keeps ownership — that is what
 * brings the patient back to them — but the patient is at the lab. Ownership and
 * location are separate axes, and only this class is allowed to reconcile them.
 */
class ResolveVisitStagesUseCase
{
    /**
     * The resolver's "no open order holds this visit" answer.
     */
    private const NOTHING_OUTSTANDING = 'with_clinician';

    public function __construct(
        private readonly ResolveConsultationDiagnosticStepsUseCase $diagnosticStepResolver,
    ) {}

    /**
     * @param  array<int, array<string, mixed>|object>  $appointments  Keyed however the caller
     *   likes; each value is an appointment array or model. Batched so a list
     *   endpoint resolves every row in one pass rather than per item.
     * @return array<string, string|null> appointmentId => step value
     */
    public function forAppointments(array $appointments): array
    {
        $baseSteps = [];
        $appointmentIds = [];

        foreach ($appointments as $appointment) {
            $id = trim((string) (is_array($appointment) ? ($appointment['id'] ?? '') : ($appointment->id ?? '')));
            if ($id === '') {
                continue;
            }

            $baseSteps[$id] = PatientFlowStep::forAppointment($appointment);
            $appointmentIds[] = $id;
        }

        if ($appointmentIds === []) {
            return [];
        }

        $diagnosticSteps = $this->diagnosticStepResolver->resolveForAppointmentIds($appointmentIds);

        $stages = [];

        foreach ($baseSteps as $id => $baseStep) {
            $stages[$id] = $this->reconcile($baseStep, $diagnosticSteps[$id]['step'] ?? null)?->value;
        }

        return $stages;
    }

    /**
     * Single-appointment convenience for the profile and single-resource
     * responses. Same rules; one row instead of a batch.
     */
    public function forAppointment(array|object|null $appointment): ?string
    {
        if ($appointment === null) {
            return null;
        }

        $id = trim((string) (is_array($appointment) ? ($appointment['id'] ?? '') : ($appointment->id ?? '')));
        if ($id === '') {
            // No id means nothing to look orders up by, so the appointment's own
            // columns are the whole answer available.
            return PatientFlowStep::forAppointment($appointment)?->value;
        }

        return $this->forAppointments([$appointment])[$id] ?? null;
    }

    private function reconcile(?PatientFlowStep $baseStep, ?string $diagnosticStep): ?PatientFlowStep
    {
        if ($baseStep === null) {
            return null;
        }

        // Nothing open holds this visit, so the appointment's own step is right.
        if ($diagnosticStep === null || $diagnosticStep === self::NOTHING_OUTSTANDING) {
            return $baseStep;
        }

        // A nurse has the patient in front of them. Physical contact outranks a
        // pending order — the specimen can wait, the person cannot be in two
        // places.
        if ($baseStep === PatientFlowStep::WITH_NURSE || $baseStep === PatientFlowStep::IN_TRIAGE) {
            return $baseStep;
        }

        return PatientFlowStep::tryFrom($diagnosticStep) ?? $baseStep;
    }
}
