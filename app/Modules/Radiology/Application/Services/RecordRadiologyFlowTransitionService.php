<?php

namespace App\Modules\Radiology\Application\Services;

use App\Modules\PatientFlow\Application\Services\RecordPatientFlowTransitionService;
use App\Modules\PatientFlow\Application\UseCases\ResolveConsultationDiagnosticStepsUseCase;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;

/**
 * Radiology's adapter onto RecordPatientFlowTransitionService — the single write
 * door every other module already uses. Radiology fired only
 * RadiologyOrderCompleted, so scheduling a study, starting it, reporting it and
 * releasing it were invisible to the Activity timeline and to every other
 * workspace's board: a patient sent for imaging sat in `in_imaging` until
 * somebody noticed by hand.
 *
 * Deliberately a mirror of RecordLaboratoryFlowTransitionService. Both modules
 * are diagnostic detours off a consultation, both hand the visit back at
 * verification, and both must answer to the same resolver — so they keep the
 * same shape rather than each inventing one.
 *
 * Two rules this class exists to keep in one place:
 *
 * 1. **The step belongs to the visit, not the order.** A patient with a chest
 *    film and an abdominal ultrasound must not move because one of them is
 *    reported. The step is therefore always re-resolved across every open order
 *    on the visit — labs included — by ResolveConsultationDiagnosticStepsUseCase,
 *    the exact code the boards read. A patient waiting on both a blood test and
 *    an X-ray resolves to `waiting_lab_and_imaging`, and radiology finishing
 *    first must not claim the visit is free.
 *
 * 2. **Imaging moves patients between queues; only a human puts someone in a
 *    room.** The resolver answers 'with_clinician' when no open order holds the
 *    visit, but that step asserts a doctor has the patient in front of them, and
 *    only the doctor's own Call Patient In may say so. This class translates
 *    that answer rather than passing it through — see resolveVisitStep().
 */
class RecordRadiologyFlowTransitionService
{
    /**
     * The resolver's "nothing open holds this visit" answer. Never written by
     * radiology; always translated first.
     */
    private const NOTHING_OUTSTANDING = 'with_clinician';

    public function __construct(
        private readonly RecordPatientFlowTransitionService $recordTransition,
        private readonly ResolveConsultationDiagnosticStepsUseCase $diagnosticStepResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $order  The radiology order as the repository returns it.
     * @param  bool  $isVerification  Verification is the only point at which radiology may
     *                                hand the visit back to the provider queue. Every other imaging event reports where
     *                                the visit already is.
     * @param  array<string, mixed>  $metadata
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

        // Direct-service imaging walk-ins carry no appointment — they already
        // flow as waiting_direct_service/in_direct_service through
        // ServiceRequest, a separate path deliberately left alone here.
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
            // Every imaging event is dated work: a named person scheduled this
            // study, performed it, reported it, released it. All of it belongs
            // on the timeline whether or not the patient moves — and often they
            // do not, because a visit with two open studies stays exactly where
            // it is until the last one is done.
            //
            // The same-step guard exists to stop board refreshes re-asserting
            // the current status and burying the timeline in noise. These are
            // discrete human actions, not refreshes, so it is waived here — the
            // same mechanism the laboratory and vitals paths rely on.
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
            // The report is released and nothing else is outstanding, so
            // radiology declares the blocking work finished. This is a queue
            // state, not a contact state — it claims the doctor can now pick
            // this patient up, not that the patient is at the doctor's door.
            //
            // Deliberately noisy in the failure case: moving too early means a
            // doctor calls an empty corridor, which is visible and
            // self-correcting. Never moving means the patient sits in imaging
            // forever with a finished report, and the board agrees with the
            // mistake.
            return PatientFlowStep::WAITING_CLINICIAN_REVIEW;
        }

        // A completed-but-unverified study is not in openWorklistValues(), so
        // the resolver stops counting it the moment the report is entered — but
        // radiology genuinely still holds this visit until someone releases it.
        // in_imaging is the honest answer for that window.
        return PatientFlowStep::IN_IMAGING;
    }
}
