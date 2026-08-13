<?php

namespace App\Modules\Notifications\Application\UseCases;

use App\Modules\Notifications\Domain\Repositories\NotificationRepositoryInterface;

class ListNotificationsUseCase
{
    public function __construct(
        private readonly NotificationRepositoryInterface $notificationRepository,
    ) {}

    public function execute(int $userId, array $filters = []): array
    {
        return $this->notificationRepository->listForUser($userId, $filters);
    }
}
