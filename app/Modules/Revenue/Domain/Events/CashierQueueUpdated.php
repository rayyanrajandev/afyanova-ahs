<?php

namespace App\Modules\Revenue\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something the cashier queue is derived from has changed.
 *
 * Carries the facility and nothing else, following PatientFlowBoardUpdated:
 * the payload is a trigger, never a data source. A broadcast that carried
 * queue rows would be a second copy of the ledger travelling over a wire, free
 * to disagree with the ledger by the time it arrived — and "two views of the
 * same money that disagree" is the defect this whole rebuild removed.
 *
 * Its own channel rather than patient-flow.{facilityId}: a charge being raised
 * or settled is of no interest to nursing, laboratory or radiology, and the
 * cashier does not need to be woken by every clinical transition in the
 * building.
 */
class CashierQueueUpdated implements ShouldBroadcast
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

        return [new PrivateChannel('cashier-queue.'.$this->facilityId)];
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }
}
