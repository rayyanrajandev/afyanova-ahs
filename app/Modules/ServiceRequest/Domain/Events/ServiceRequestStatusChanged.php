<?php

namespace App\Modules\ServiceRequest\Domain\Events;

/**
 * Patient-Flow Board Phase 3: dispatched by
 * UpdateServiceRequestStatusUseCase::execute() after its transaction commits
 * (DB::afterCommit(), mirroring AppointmentStatusChanged in the Appointment
 * module) so a listener never reacts to a status change that ultimately
 * rolled back. This use case is the single call site every direct-service
 * status transition funnels through (one route,
 * PATCH service-requests/{id}/status), so this event covers every
 * transition uniformly.
 *
 * Closes a real gap: the Patient-Flow Board's waiting_direct_service/
 * in_direct_service columns are driven by ServiceRequestModel
 * (GetActiveVisitJourneyUseCase queries it directly), but until this event
 * existed, ServiceRequest had zero domain events at all — that slice of the
 * board silently rode the 30s poll fallback even after Phase 2 shipped live
 * push for the board as a whole.
 *
 * A plain domain event, not a broadcasting one — ServiceRequest has no
 * opinion about the Patient-Flow Board's transport. $facilityId is carried
 * purely so PatientFlow\Application\Listeners\BroadcastPatientFlowBoardUpdate
 * knows which facility-scoped channel to re-broadcast on.
 */
class ServiceRequestStatusChanged
{
    public function __construct(
        public readonly string $serviceRequestId,
        public readonly string $patientId,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly ?int $actorId,
        public readonly ?string $facilityId = null,
    ) {}
}
