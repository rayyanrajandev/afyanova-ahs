<?php

namespace App\Modules\Notifications\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Application\UseCases\DismissNotificationUseCase;
use App\Modules\Notifications\Application\UseCases\GetUnreadCountUseCase;
use App\Modules\Notifications\Application\UseCases\ListNotificationsUseCase;
use App\Modules\Notifications\Application\UseCases\MarkAllAsReadUseCase;
use App\Modules\Notifications\Application\UseCases\MarkAsReadUseCase;
use App\Modules\Notifications\Presentation\Http\Transformers\NotificationTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly ListNotificationsUseCase $listNotificationsUseCase,
        private readonly GetUnreadCountUseCase $getUnreadCountUseCase,
        private readonly MarkAsReadUseCase $markAsReadUseCase,
        private readonly MarkAllAsReadUseCase $markAllAsReadUseCase,
        private readonly DismissNotificationUseCase $dismissNotificationUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $filters = $request->validate([
            'read' => 'nullable|boolean',
            'category' => 'nullable|string|in:clinical,laboratory,pharmacy,billing,administration,system',
            'priority' => 'nullable|string|in:critical,high,normal,informational',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $result = $this->listNotificationsUseCase->execute($userId, $filters);

        $result['data'] = NotificationTransformer::collection(
            array_map(fn (mixed $item): array => $item instanceof \App\Modules\Notifications\Infrastructure\Models\NotificationModel ? $item->toArray() : (array) $item, $result['data']),
        );

        return response()->json($result);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $count = $this->getUnreadCountUseCase->execute($userId);

        return response()->json(['data' => ['count' => $count]]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->markAsReadUseCase->execute($id);

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return response()->json([
            'data' => NotificationTransformer::transform($notification),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $count = $this->markAllAsReadUseCase->execute($userId);

        return response()->json(['data' => ['updated' => $count]]);
    }

    public function dismiss(Request $request, string $id): JsonResponse
    {
        $notification = $this->dismissNotificationUseCase->execute($id);

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return response()->json(['message' => 'Notification dismissed.']);
    }
}
