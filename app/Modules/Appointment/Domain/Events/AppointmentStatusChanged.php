<?php

namespace App\Modules\Appointment\Domain\Events;

/**
 * Patient-Flow Board Phase 2: dispatched by
 * UpdateAppointmentStatusUseCase::execute() after its status write and audit
 * log, wrapped in DB::afterCommit() (mirroring AppointmentCheckedIn in the
 * Reception module) so a listener never reacts to a status change that
 * ultimately rolled back. This use case is the single call site every
 * controller action funnels through (updateStatus, startConsultation,
 * updateProviderWorkflow, etc.), so this event covers every appointment
 * status transition uniformly — the gap AppointmentCheckedIn alone didn't
 * cover (that event only fires for the specific check-in transition).
 *
 * A plain domain event, not a broadcasting one — Appointment has no opinion
 * about the Patient-Flow Board's transport. $facilityId is carried purely so
 * PatientFlow\Application\Listeners\BroadcastPatientFlowBoardUpdate knows
 * which facility-scoped channel to re-broadcast on.
 */
class AppointmentStatusChanged
{
    public function __construct(
        public readonly string $appointmentId,
        public readonly string $patientId,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly ?int $actorId,
        public readonly ?string $facilityId = null,
    ) {}
}
