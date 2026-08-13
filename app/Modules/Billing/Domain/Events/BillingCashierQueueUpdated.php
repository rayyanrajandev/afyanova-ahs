<?php

namespace App\Modules\Billing\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mirrors PatientFlowBoardUpdated's shape (App\Modules\PatientFlow\Domain\
 * Events\PatientFlowBoardUpdated): billing invoice/payment activity isn't
 * one of that event's triggers (check-in, appointment status change, lab/
 * pharmacy/radiology completion, direct-service status change), so the
 * cashier queue gets its own private channel/event rather than piggybacking
 * on an unrelated one.
 *
 * implements ShouldBroadcast (queued), not ShouldBroadcastNow — a payment
 * being recorded must never block on a live network call to Reverb; a
 * stalled queue worker just means the queue falls back to its 30s poll
 * instead of instant push.
 *
 * Deliberately carries only facilityId — listeners invalidate the cashier
 * queue's query cache and let the existing ListCashierQueueUseCase pipeline
 * refetch, rather than duplicating that derivation into a second payload
 * shape pushed over the wire.
 */
class BillingCashierQueueUpdated implements ShouldBroadcast
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

        return [new PrivateChannel('billing-queue.'.$this->facilityId)];
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }
}
