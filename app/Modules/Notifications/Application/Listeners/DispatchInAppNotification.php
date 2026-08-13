<?php

namespace App\Modules\Notifications\Application\Listeners;

use App\Modules\Notifications\Domain\Events\NotificationDispatched;
use App\Modules\Notifications\Domain\Repositories\NotificationRepositoryInterface;
use App\Modules\Notifications\Infrastructure\Models\NotificationModel;
use Illuminate\Support\Facades\Log;

class DispatchInAppNotification
{
    public function __construct(
        private readonly NotificationRepositoryInterface $notificationRepository,
    ) {}

    public function handle(
        int $userId,
        string $category,
        string $priority,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
        ?string $contextType = null,
        ?string $contextId = null,
    ): void {
        try {
            $notification = $this->notificationRepository->create([
                'user_id' => $userId,
                'category' => $category,
                'priority' => $priority,
                'title' => $title,
                'body' => $body,
                'action_url' => $actionUrl,
                'action_label' => $actionLabel,
                'context_type' => $contextType,
                'context_id' => $contextId,
            ]);

            $unreadCount = $this->notificationRepository->unreadCount($userId);

            event(new NotificationDispatched(
                userId: $userId,
                notificationId: $notification['id'],
                category: $category,
                priority: $priority,
                title: $title,
                body: $body,
                actionUrl: $actionUrl,
                actionLabel: $actionLabel,
                unreadCount: $unreadCount,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch in-app notification', [
                'user_id' => $userId,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
