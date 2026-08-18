<?php

namespace App\Modules\Pharmacy\Application\Services;

use App\Modules\PatientFlow\Application\Services\RecordPatientFlowTransitionService;
use App\Modules\PatientFlow\Application\UseCases\ResolveConsultationDiagnosticStepsUseCase;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;

/**
 * Pharmacy's adapter onto RecordPatientFlowTransitionService.
 *
 * The read side already worked: ResolveConsultationDiagnosticStepsUseCase counts
 * open pharmacy orders and answers `waiting_pharmacy`, so boards showed a
 * patient waiting for medication. What was missing was the write side —
 * preparing and dispensing reached no timeline, so nobody outside the pharmacy
 * could see who had done what, or when.
 *
 * Shaped like the laboratory and radiology adapters, with one deliberate
 * difference in where the visit goes at the end.
 *
 * ## Where pharmacy hands the patient back
 *
 * Laboratory and radiology hand back to `waiting_clinician_review`, because a
 * result is work the doctor still has to read. A dispensed prescription is not:
 * the patient has what they came for, and nothing clinical is outstanding. They
 * are going to the front, not back to the consulting room — so pharmacy hands
 * back to `returned_to_reception`, the same step nursing already uses when it
 * releases a patient it no longer holds.
 *
 * Deliberately **not** `completed`. That step is terminal, nothing in the system
 * writes it through this door today, and ending a visit is not pharmacy's call:
 * a patient may still owe a payment or need a follow-up booked. Pharmacy may say
 * "we are finished with this patient"; only reception may say "this visit is
 * over".
 *
 * ## Partial dispensing
 *
 * `partially_dispensed` is in PharmacyOrderStatus::openWorklistValues(), so the
 * resolver keeps answering `waiting_pharmacy` while any medicine is still owed.
 * A half-filled prescription therefore holds the visit exactly as an unstarted
 * one does, with no special case needed here.
 */
class RecordPharmacyFlowTransitionService
{
    /**
     * The resolver's "nothing open holds this visit" answer. Never written by
     * pharmacy; always translated first.
     */
    private const NOTHING_OUTSTANDING = 'with_clinician';

    public function __construct(
        private readonly RecordPatientFlowTransitionService $recordTransition,
        private readonly ResolveConsultationDiagnosticStepsUseCase $diagnosticStepResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $order  The pharmacy order as the repository returns it.
     * @param  bool  $isDispenseComplete  True only when the last medicine owed on this
     *                                    order has been handed over. That is the one point at which pharmacy may release
     *                                    the visit; every other event reports where it already is.
     * @param  array<string, mixed>  $metadata
     */
    public function recordForOrder(
        array $order,
        string $source,
        ?int $actorId,
        bool $isDispenseComplete = false,
        array $metadata = [],
    ): void {
        $patientId = $order['patient_id'] ?? null;
        $appointmentId = $order['appointment_id'] ?? null;

        // An over-the-counter sale or a walk-in carries no appointment; those
        // already flow through ServiceRequest as direct service.
        if ($patientId === null || $appointmentId === null) {
            return;
        }

        $step = $this->resolveVisitStep((string) $appointmentId, $isDispenseComplete);
        if ($step === null) {
            return;
        }

        $this->recordTransition->record(
            toStep: $step,
            patientId: (string) $patientId,
            appointmentId: (string) $appointmentId,
            actorId: $actorId,
            source: $source,
            metadata: $metadata,
            facilityId: $order['facility_id'] ?? null,
            // Dated human work, whether or not the patient moves — a partial
            // dispense leaves them exactly where they were and still belongs on
            // the timeline. Same waiver the laboratory and radiology paths use.
            allowSameStep: true,
        );
    }

    /**
     * Where the *visit* stands once this order's change is in the database.
     *
     * Called after the write, so the resolver sees the new state.
     */
    private function resolveVisitStep(string $appointmentId, bool $isDispenseComplete): ?PatientFlowStep
    {
        $resolved = $this->diagnosticStepResolver->resolveForAppointmentIds([$appointmentId]);
        $step = $resolved[$appointmentId]['step'] ?? null;

        if ($step === null) {
            return null;
        }

        if ($step !== self::NOTHING_OUTSTANDING) {
            // Something still holds this visit — another prescription, or a lab
            // or imaging order that outranks pharmacy in the resolver's
            // precedence. The patient is not going anywhere yet.
            return PatientFlowStep::tryFrom($step);
        }

        if ($isDispenseComplete) {
            return PatientFlowStep::RETURNED_TO_RECEPTION;
        }

        // Nothing outstanding but the dispense is not finished: the order left
        // the open worklist without being handed over — cancelled, most likely.
        // Pharmacy has no claim to make about where the patient goes.
        return null;
    }
}
