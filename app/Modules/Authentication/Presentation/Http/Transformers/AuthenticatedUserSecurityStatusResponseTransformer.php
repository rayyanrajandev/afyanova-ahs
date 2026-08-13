<?php

namespace App\Modules\Authentication\Presentation\Http\Transformers;

class AuthenticatedUserSecurityStatusResponseTransformer
{
    public static function transform(array $status): array
    {
        return [
            'emailVerified' => (bool) ($status['email_verified'] ?? false),
            'emailVerifiedAt' => $status['email_verified_at'] ?? null,
            'twoFactorEnabled' => (bool) ($status['two_factor_enabled'] ?? false),
            'twoFactorConfirmed' => (bool) ($status['two_factor_confirmed'] ?? false),
            'twoFactorConfirmedAt' => $status['two_factor_confirmed_at'] ?? null,
        ];
    }
}
