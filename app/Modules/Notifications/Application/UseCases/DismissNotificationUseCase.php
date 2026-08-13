<?php

namespace App\Modules\Notifications\Application\UseCases;

use App\Modules\Notifications\Domain\Repositories\NotificationRepositoryInterface;

class DismissNotificationUseCase
{
    public function __construct(
        private readonly NotificationRepositoryInterface $notificationRepository,
    ) {}

    public function execute(string $id): ?array
    {
        return $this->notificationRepository->dismiss($id);
    }
}
