<?php

namespace App\Modules\Laboratory\Application\Services;

use App\Modules\PatientFlow\Application\Services\RecordPatientFlowTransitionService;
use App\Modules\PatientFlow\Application\UseCases\ResolveConsultationDiagnosticStepsUseCase;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;

/**
 * Laboratory's adapter onto RecordPatientFlowTransitionService — the single
 * write door every other module already uses. Before this, Laboratory recorded
 * no flow events at all: specimen collection, testing and verification were
 * invisible to the Activity timeline and to every other workspace's board, so
 * nobody outside the lab could see who had done what, or when
 * (reports/laboratory-workspace-flow-plan.md, phase 2).
 *
 * Two rules this class exists to keep in one place:
 *
 * 1. **The step belongs to the visit, not the order.** A patient with three
 *    open labs must not move because one of them finished. So the step is
 *    always re-resolved across every open order on the visit, by
 *    ResolveConsultationDiagnosticStepsUseCase — the exact code the boards
 *    read. Deriving it from the single order being written would be a second
 *    rule, and second rules drift.
 *
 * 2. **The lab moves patients between queues; only a human puts someone in a
 *    room.** The resolver answers 'with_clinician' when no open order holds the
 *    visit, but that step means a doctor has the patient in front of them, and
 *    only the doctor's own Call Patient In may assert that. So this class
 *    translates that answer instead of passing it through — see
 *    resolveVisitStep().
 */
class RecordLaboratoryFlowTransitionService
{
    /**
     * The resolver's "nothing open holds this visit" answer. Never written by
     * the lab; always translated first.
     */
    private const NOTHING_OUTSTANDING = 'with_clinician';

    public function __construct(
        private readonly RecordPatientFlowTransitionService $recordTransition,
        private readonly ResolveConsultationDiagnosticStepsUseCase $diagnosticStepResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $order  The laboratory order as the repository returns it.
     * @param  bool  $isVerification  Applies Decision 1: verification is the only point at
     *   which the lab may hand the visit back to the provider queue. Every other lab event
     *   reports where the visit already is.
     */
    public function recordForOrder(
        array $order,
        string $source,
        ?int $actorId,
        bool $isVerification = false,
        array $metadata = [],
    ): void {
        $patientId = $order['patient_id'] ?? null;
        $appointmentId = $order['appointment_id'] ?? null;

        // Direct-service lab walk-ins carry no appointment — they already flow
        // as waiting_direct_service/in_direct_service through ServiceRequest,
        // a separate path deliberately left alone here.
        if ($patientId === null || $appointmentId === null) {
            return;
        }

        $step = $this->resolveVisitStep((string) $appointmentId, $isVerification);
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
            // Every lab event is dated work: a named person collected this
            // specimen, started this test, entered this result, released it.
            // All of it belongs on the timeline whether or not the patient
            // moves — and often they do not, because a visit with three open
            // labs stays exactly where it is until the last one is done.
            //
            // The same-step guard exists to stop board refreshes re-asserting
            // the current status and burying the timeline in noise. These are
            // discrete human actions, not refreshes, so it is waived here — the
            // same mechanism the vitals path already relies on.
            allowSameStep: true,
        );
    }

    /**
     * Where the *visit* stands once this order's change is in the database.
     *
     * Called after the write, so the resolver sees the new state.
     */
    private function resolveVisitStep(string $appointmentId, bool $isVerification): ?PatientFlowStep
    {
        $resolved = $this->diagnosticStepResolver->resolveForAppointmentIds([$appointmentId]);
        $step = $resolved[$appointmentId]['step'] ?? null;

        if ($step === null) {
            return null;
        }

        if ($step !== self::NOTHING_OUTSTANDING) {
            // Something is still open on this visit — including, commonly, the
            // very order being written. The patient has not been handed back.
            return PatientFlowStep::tryFrom($step);
        }

        if ($isVerification) {
            // Decision 1: results are verified and nothing else is outstanding,
            // so the lab declares the blocking work finished. This is a queue
            // state, not a contact state — it claims the doctor can now pick
            // this patient up, not that the patient is at the doctor's door.
            //
            // Deliberately noisy in the failure case: moving too early means a
            // doctor calls an empty corridor, which is visible and
            // self-correcting. Never moving means the patient sits in the lab
            // forever with finished results, and the board agrees with the
            // mistake.
            return PatientFlowStep::WAITING_CLINICIAN_REVIEW;
        }

        // A completed-but-unverified order is not in openWorklistValues(), so
        // the resolver stops counting it the moment the result is entered — but
        // the lab genuinely still holds this visit until someone verifies it.
        // in_lab is the honest answer for that window.
        return PatientFlowStep::IN_LAB;
    }
}
