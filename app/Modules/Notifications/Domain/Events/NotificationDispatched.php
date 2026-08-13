<?php

namespace App\Modules\Notifications\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationDispatched implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $notificationId,
        public readonly string $category,
        public readonly string $priority,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $actionUrl,
        public readonly ?string $actionLabel,
        public readonly int $unreadCount,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('notifications.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.dispatched';
    }
}
