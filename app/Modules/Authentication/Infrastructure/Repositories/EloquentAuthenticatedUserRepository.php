<?php

namespace App\Modules\Authentication\Infrastructure\Repositories;

use App\Models\User;
use App\Modules\Authentication\Domain\Repositories\AuthenticatedUserRepositoryInterface;

class EloquentAuthenticatedUserRepository implements AuthenticatedUserRepositoryInterface
{
    public function findById(int $userId): ?array
    {
        $user = User::query()
            ->with(['roles:id,code,name'])
            ->find($userId);

        return $user?->makeVisible([
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
        ])->toArray();
    }

    public function listPermissionNames(int $userId): array
    {
        $user = User::query()
            ->with(['roles:id,code,name'])
            ->find($userId);
        if (! $user) {
            return [];
        }

        return $user->permissionNames();
    }
}


