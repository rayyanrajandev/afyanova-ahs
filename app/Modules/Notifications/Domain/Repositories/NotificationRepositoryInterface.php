<?php

namespace App\Modules\Notifications\Domain\Repositories;

interface NotificationRepositoryInterface
{
    public function findById(string $id): ?array;

    public function listForUser(int $userId, array $filters = []): array;

    public function unreadCount(int $userId): int;

    public function create(array $attributes): array;

    public function markAsRead(string $id): ?array;

    public function markAllAsRead(int $userId): int;

    public function dismiss(string $id): ?array;
}
