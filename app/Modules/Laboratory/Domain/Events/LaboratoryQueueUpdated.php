<?php

namespace App\Modules\Laboratory\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something in the laboratory workbench queue has changed.
 *
 * Carries only the facility ID trigger: the payload is a trigger, never a data source.
 * When a listener receives this broadcast, it refetches its active worklist over HTTP.
 */
class LaboratoryQueueUpdated implements ShouldBroadcast
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

        return [new PrivateChannel('laboratory-queue.'.$this->facilityId)];
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }
}
