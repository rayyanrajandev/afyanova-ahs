<?php

namespace App\Modules\Notifications\Application\UseCases;

use App\Modules\Notifications\Domain\Repositories\NotificationRepositoryInterface;

class MarkAllAsReadUseCase
{
    public function __construct(
        private readonly NotificationRepositoryInterface $notificationRepository,
    ) {}

    public function execute(int $userId): int
    {
        return $this->notificationRepository->markAllAsRead($userId);
    }
}
