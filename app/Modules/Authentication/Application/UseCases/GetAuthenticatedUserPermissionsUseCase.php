<?php

namespace App\Modules\Authentication\Application\UseCases;

use App\Modules\Authentication\Domain\Repositories\AuthenticatedUserRepositoryInterface;

class GetAuthenticatedUserPermissionsUseCase
{
    public function __construct(private readonly AuthenticatedUserRepositoryInterface $authenticatedUserRepository) {}

    /**
     * @return array<int, string>
     */
    public function execute(int $userId): array
    {
        $permissions = $this->authenticatedUserRepository->listPermissionNames($userId);

        $normalized = array_values(array_unique(array_filter(
            $permissions,
            static fn (mixed $permission): bool => is_string($permission) && $permission !== '',
        )));
        sort($normalized);

        return $normalized;
    }
}
