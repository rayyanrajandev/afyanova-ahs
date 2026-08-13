<?php

namespace App\Modules\PatientFlow\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Patient-Flow Board Phase 2: the *only* broadcasting event in the whole
 * feature. App\Modules\PatientFlow\Application\Listeners\
 * BroadcastPatientFlowBoardUpdate translates the 5 source domain events
 * (AppointmentCheckedIn, AppointmentStatusChanged, LaboratoryOrderCompleted,
 * PharmacyOrderDispensed, RadiologyOrderCompleted) into this single event, so
 * the frontend listens for exactly one thing regardless of which module's
 * action triggered the update — decoupled from those 5 events' names/shapes
 * entirely.
 *
 * implements ShouldBroadcast (queued via the existing `database` queue
 * connection), not ShouldBroadcastNow — a triggering request (an appointment
 * status update, a lab result completion) must never block on a live network
 * call to Reverb; a stalled queue worker just means the board falls back to
 * its 30s poll instead of instant push, which is a far better failure mode
 * than coupling core clinical-workflow request handling to broadcast-infra
 * reliability.
 *
 * Deliberately carries only facilityId, nothing else — every listener does
 * on receipt is invalidate the board's query cache and let the existing
 * GetActiveVisitJourneyUseCase pipeline refetch, rather than duplicating that
 * use case's derivation into a second, potentially-drifting payload shape
 * pushed over the wire.
 */
class PatientFlowBoardUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ?string $facilityId) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if ($this->facilityId === null) {
            return [];
        }

        return [new PrivateChannel('patient-flow.'.$this->facilityId)];
    }

    public function broadcastAs(): string
    {
        return 'board.updated';
    }
}
