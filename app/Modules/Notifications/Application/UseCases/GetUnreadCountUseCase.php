<?php

namespace App\Modules\Notifications\Application\UseCases;

use App\Modules\Notifications\Domain\Repositories\NotificationRepositoryInterface;

class GetUnreadCountUseCase
{
    public function __construct(
        private readonly NotificationRepositoryInterface $notificationRepository,
    ) {}

    public function execute(int $userId): int
    {
        return $this->notificationRepository->unreadCount($userId);
    }
}
