<?php

namespace App\Modules\Notifications\Application\UseCases;

use App\Modules\Notifications\Domain\Repositories\NotificationRepositoryInterface;

class MarkAsReadUseCase
{
    public function __construct(
        private readonly NotificationRepositoryInterface $notificationRepository,
    ) {}

    public function execute(string $id): ?array
    {
        return $this->notificationRepository->markAsRead($id);
    }
}
