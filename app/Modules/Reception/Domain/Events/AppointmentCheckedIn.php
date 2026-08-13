<?php

namespace App\Modules\Reception\Domain\Events;

/**
 * Phase 5 (Mode A->B->C automation) of
 * reports/patient-arrival-checkin-modernization-plan.md §3.3: dispatched by
 * CheckInUseCase after its transaction commits (DB::afterCommit(), so a
 * listener never reacts to a check-in that ultimately rolled back), so
 * downstream automation can react to arrival without CheckInUseCase itself
 * knowing or caring what that automation is — "no new side effects inline
 * in this class" (plan §3.2).
 *
 * $facilityId (Patient-Flow Board Phase 2) is carried purely so
 * PatientFlow\Application\Listeners\BroadcastPatientFlowBoardUpdate knows
 * which facility-scoped channel to re-broadcast on — this event itself
 * stays a plain, non-broadcasting domain event; Reception has no opinion
 * about the board's transport.
 */
class AppointmentCheckedIn
{
    public function __construct(
        public readonly string $appointmentId,
        public readonly string $patientId,
        public readonly string $arrivalMode,
        public readonly ?int $actorId,
        public readonly ?string $facilityId = null,
    ) {}
}
