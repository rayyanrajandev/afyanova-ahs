<?php

namespace App\Modules\Authentication\Domain\Repositories;

interface AuthenticatedUserRepositoryInterface
{
    public function findById(int $userId): ?array;

    /**
     * @return array<int, string>
     */
    public function listPermissionNames(int $userId): array;
}
