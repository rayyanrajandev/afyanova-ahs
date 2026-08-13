<?php

namespace App\Modules\Laboratory\Domain\Events;

/**
 * Phase 0 of reports/queue-based-workflow-modernization-plan.md §3.2:
 * dispatched by UpdateLaboratoryOrderStatusUseCase after its transaction
 * commits (DB::afterCommit(), mirroring AppointmentCheckedIn in the
 * Reception module), so a listener never reacts to a result that ultimately
 * rolled back. Consumed by LogOrderCompletionForOrderingClinician (shadow
 * logging) and, since Patient-Flow Board Phase 2,
 * BroadcastPatientFlowBoardUpdate.
 *
 * $facilityId is carried purely so BroadcastPatientFlowBoardUpdate knows
 * which facility-scoped channel to re-broadcast on — this event itself
 * stays a plain, non-broadcasting domain event; Laboratory has no opinion
 * about the board's transport.
 */
class LaboratoryOrderCompleted
{
    public function __construct(
        public readonly string $laboratoryOrderId,
        public readonly string $patientId,
        public readonly ?string $appointmentId,
        public readonly ?int $orderedByUserId,
        public readonly ?int $actorId,
        public readonly ?string $facilityId = null,
    ) {}
}
