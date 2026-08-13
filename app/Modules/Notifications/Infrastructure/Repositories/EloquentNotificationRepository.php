<?php

namespace App\Modules\Notifications\Infrastructure\Repositories;

use App\Modules\Notifications\Domain\Repositories\NotificationRepositoryInterface;
use App\Modules\Notifications\Infrastructure\Models\NotificationModel;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentNotificationRepository implements NotificationRepositoryInterface
{
    public function findById(string $id): ?array
    {
        $model = NotificationModel::find($id);
        return $model?->toArray();
    }

    public function listForUser(int $userId, array $filters = []): array
    {
        $query = NotificationModel::where('user_id', $userId);

        if (isset($filters['read']) && $filters['read'] === false) {
            $query->whereNull('read_at');
        }

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        $query->whereNull('dismissed_at')->orderBy('created_at', 'desc');

        $perPage = min($filters['per_page'] ?? 20, 100);
        $page = $filters['page'] ?? 1;

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function unreadCount(int $userId): int
    {
        return NotificationModel::where('user_id', $userId)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->count();
    }

    public function create(array $attributes): array
    {
        $model = new NotificationModel();
        $model->fill($attributes);
        $model->save();
        return $model->toArray();
    }

    public function markAsRead(string $id): ?array
    {
        $model = NotificationModel::find($id);
        if (! $model) {
            return null;
        }
        $model->read_at = now();
        $model->save();
        return $model->toArray();
    }

    public function markAllAsRead(int $userId): int
    {
        return NotificationModel::where('user_id', $userId)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->update(['read_at' => now()]);
    }

    public function dismiss(string $id): ?array
    {
        $model = NotificationModel::find($id);
        if (! $model) {
            return null;
        }
        $model->dismissed_at = now();
        $model->save();
        return $model->toArray();
    }
}
