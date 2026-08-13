<?php

namespace App\Modules\Authentication\Application\UseCases;

use App\Modules\Authentication\Domain\Repositories\AuthenticatedUserRepositoryInterface;

class GetAuthenticatedUserProfileUseCase
{
    public function __construct(private readonly AuthenticatedUserRepositoryInterface $authenticatedUserRepository) {}

    public function execute(int $userId): ?array
    {
        return $this->authenticatedUserRepository->findById($userId);
    }
}
