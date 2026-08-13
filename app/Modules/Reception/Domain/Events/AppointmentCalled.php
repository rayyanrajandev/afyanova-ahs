<?php

namespace App\Modules\Reception\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Volume 2.1 §10.3 "Call" — decided 2026-08-11 (Volume 3.7 §16 #3, the
 * decision routes/api-workspaces.php's disabled route comment was left
 * waiting on): an ephemeral broadcast-only notification, not a new
 * `AppointmentStatus` case. "Called" is transient front-desk signaling
 * ("please come to the counter"), not a clinical event — it has no
 * business being a permanent state on the appointment record the way
 * check-in/cancel are (both of which write to the patient's own audit
 * trail; this deliberately does not). Adding a persisted status would also
 * mean extending `AppointmentStatus::allowedForwardTransitions()`'s real,
 * guarded transition graph for a fact nothing downstream needs to query
 * later — no report, no clinical workflow, ever needs "was this patient
 * called at 10:32am" after the moment has passed.
 *
 * Unlike PatientFlowBoardUpdated (patient-flow.{facilityId}, deliberately
 * minimal payload — just facilityId, "invalidate and refetch"), this event
 * carries its own content (appointmentId, patientName) because there is no
 * persisted state for a receiving client to refetch — the broadcast IS the
 * entire fact, by design. Broadcasts on its own reception-queue.{facilityId}
 * channel, not patient-flow.{facilityId}: this is Reception-only signaling
 * (nursing/lab/pharmacy/radiology have no reason to know a patient was
 * called from the front desk), unlike patient-flow's genuinely
 * cross-workspace triggers — reusing that channel would conflate two
 * different kinds of signal (invalidation vs. content-bearing) on one wire.
 */
class AppointmentCalled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $facilityId,
        public readonly string $appointmentId,
        public readonly string $patientName,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('reception-queue.'.$this->facilityId)];
    }

    public function broadcastAs(): string
    {
        return 'queue.appointment-called';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'appointmentId' => $this->appointmentId,
            'patientName' => $this->patientName,
        ];
    }
}
