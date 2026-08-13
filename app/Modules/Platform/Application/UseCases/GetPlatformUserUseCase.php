<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\PlatformUserAdminRepositoryInterface;

class GetPlatformUserUseCase
{
    public function __construct(private readonly PlatformUserAdminRepositoryInterface $platformUserAdminRepository) {}

    public function execute(int $id): ?array
    {
        return $this->platformUserAdminRepository->findUserById($id);
    }
}

