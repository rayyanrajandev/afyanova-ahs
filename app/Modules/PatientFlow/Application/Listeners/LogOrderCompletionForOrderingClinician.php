<?php

namespace App\Modules\PatientFlow\Application\Listeners;

use App\Modules\Laboratory\Domain\Events\LaboratoryOrderCompleted;
use App\Modules\PatientFlow\Application\UseCases\GetActiveVisitJourneyUseCase;
use App\Modules\Pharmacy\Domain\Events\PharmacyOrderDispensed;
use App\Modules\Radiology\Domain\Events\RadiologyOrderCompleted;
use App\Modules\ClinicalProcedure\Domain\Events\ClinicalProcedureOrderCompleted;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mode A (shadow, inert) of reports/queue-based-workflow-modernization-plan.md
 * §3.3: logs what a real notification to the ordering clinician would look
 * like — and what the visit's journey step becomes now that this order is
 * off the "open" list — without notifying anyone or writing anything. A pure
 * observer, mirroring Reception's LogShadowEmergencyTriageCaseCreation: never
 * writes to the database, dispatches no further events, and a logging
 * failure here must never surface as a failure of the order-completion write
 * it observed (hence the try/catch).
 *
 * Purpose: accumulate evidence that the derived step and the "who to notify"
 * signal are correct against real data before Mode B makes either visible to
 * anyone, and before Mode C notifies for real.
 */
class LogOrderCompletionForOrderingClinician
{
    public function __construct(
        private readonly GetActiveVisitJourneyUseCase $getActiveVisitJourneyUseCase,
    ) {}

    public function handleLaboratoryOrderCompleted(LaboratoryOrderCompleted $event): void
    {
        $this->log(
            sourceEvent: 'laboratory_order_completed',
            orderId: $event->laboratoryOrderId,
            patientId: $event->patientId,
            appointmentId: $event->appointmentId,
            orderedByUserId: $event->orderedByUserId,
        );
    }

    public function handlePharmacyOrderDispensed(PharmacyOrderDispensed $event): void
    {
        $this->log(
            sourceEvent: 'pharmacy_order_dispensed',
            orderId: $event->pharmacyOrderId,
            patientId: $event->patientId,
            appointmentId: $event->appointmentId,
            orderedByUserId: $event->orderedByUserId,
        );
    }

    public function handleRadiologyOrderCompleted(RadiologyOrderCompleted $event): void
    {
        $this->log(
            sourceEvent: 'radiology_order_completed',
            orderId: $event->radiologyOrderId,
            patientId: $event->patientId,
            appointmentId: $event->appointmentId,
            orderedByUserId: $event->orderedByUserId,
        );
    }

    public function handleClinicalProcedureOrderCompleted(ClinicalProcedureOrderCompleted $event): void
    {
        $this->log(
            sourceEvent: 'clinical_procedure_order_completed',
            orderId: $event->clinicalProcedureOrderId,
            patientId: $event->patientId,
            appointmentId: $event->appointmentId,
            orderedByUserId: $event->orderedByUserId,
        );
    }

    private function log(
        string $sourceEvent,
        string $orderId,
        string $patientId,
        ?string $appointmentId,
        ?int $orderedByUserId,
    ): void {
        try {
            Log::channel('patient_flow_shadow_automation')->info(
                'Mode A shadow: would notify the ordering clinician that this order is complete',
                [
                    'mode' => 'A',
                    'proposed_action' => 'notify_ordering_clinician',
                    'source_event' => $sourceEvent,
                    'order_id' => $orderId,
                    'patient_id' => $patientId,
                    'appointment_id' => $appointmentId,
                    'ordered_by_user_id' => $orderedByUserId,
                    'derived_visit_journey_step' => $this->resolveDerivedStep($appointmentId),
                ],
            );
        } catch (Throwable) {
            // Deliberately swallowed: shadow logging must never surface as a
            // failure of the order-completion write it observed.
        }
    }

    /**
     * Null when the appointment has no id (a standalone order with no
     * linked visit) or is no longer active — both are legitimate outcomes,
     * not errors, so this returns null rather than throwing.
     */
    private function resolveDerivedStep(?string $appointmentId): ?string
    {
        if ($appointmentId === null) {
            return null;
        }

        foreach ($this->getActiveVisitJourneyUseCase->execute() as $entry) {
            if ($entry['appointmentId'] === $appointmentId) {
                return $entry['step'];
            }
        }

        return null;
    }
}
