<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\PlatformRbacRepositoryInterface;

class GetPlatformRoleUseCase
{
    public function __construct(private readonly PlatformRbacRepositoryInterface $platformRbacRepository) {}

    public function execute(string $id): ?array
    {
        return $this->platformRbacRepository->findRoleById($id);
    }
}

