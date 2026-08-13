<?php

namespace App\Modules\Authentication\Presentation\Http\Transformers;

class AuthenticatedUserProfileResponseTransformer
{
    public static function transform(array $user): array
    {
        return [
            'id' => $user['id'] ?? null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'roles' => array_map(static fn (array $role): array => [
                'code' => $role['code'] ?? null,
                'name' => $role['name'] ?? null,
            ], $user['roles'] ?? []),
            'emailVerifiedAt' => $user['email_verified_at'] ?? null,
            'createdAt' => $user['created_at'] ?? null,
            'updatedAt' => $user['updated_at'] ?? null,
        ];
    }
}


