<?php

namespace App\Modules\Notifications\Presentation\Http\Transformers;

class NotificationTransformer
{
    public static function transform(array $notification): array
    {
        return [
            'id' => $notification['id'] ?? null,
            'userId' => $notification['user_id'] ?? null,
            'category' => $notification['category'] ?? null,
            'priority' => $notification['priority'] ?? null,
            'title' => $notification['title'] ?? null,
            'body' => $notification['body'] ?? null,
            'actionUrl' => $notification['action_url'] ?? null,
            'actionLabel' => $notification['action_label'] ?? null,
            'contextType' => $notification['context_type'] ?? null,
            'contextId' => $notification['context_id'] ?? null,
            'readAt' => $notification['read_at'] ? $notification['read_at'] : null,
            'dismissedAt' => $notification['dismissed_at'] ? $notification['dismissed_at'] : null,
            'createdAt' => $notification['created_at'] ?? null,
        ];
    }

    public static function collection(array $notifications): array
    {
        return array_map(fn (array $n) => self::transform($n), $notifications);
    }
}
