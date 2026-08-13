<?php

namespace App\Modules\Authentication\Application\UseCases;

use App\Modules\Authentication\Domain\Repositories\AuthenticatedUserRepositoryInterface;

class GetAuthenticatedUserSecurityStatusUseCase
{
    public function __construct(private readonly AuthenticatedUserRepositoryInterface $authenticatedUserRepository) {}

    public function execute(int $userId): ?array
    {
        $user = $this->authenticatedUserRepository->findById($userId);
        if (! $user) {
            return null;
        }

        $twoFactorSecret = $user['two_factor_secret'] ?? null;
        $twoFactorRecoveryCodes = $user['two_factor_recovery_codes'] ?? null;
        $twoFactorConfirmedAt = $user['two_factor_confirmed_at'] ?? null;
        $emailVerifiedAt = $user['email_verified_at'] ?? null;
        $twoFactorEnabled = $this->hasTwoFactorSecret($twoFactorSecret) && $this->hasRecoveryCodes($twoFactorRecoveryCodes);

        return [
            'email_verified' => $emailVerifiedAt !== null,
            'email_verified_at' => $emailVerifiedAt,
            'two_factor_enabled' => $twoFactorEnabled,
            'two_factor_confirmed' => $twoFactorEnabled && $twoFactorConfirmedAt !== null,
            'two_factor_confirmed_at' => $twoFactorConfirmedAt,
        ];
    }

    private function hasTwoFactorSecret(mixed $secret): bool
    {
        return is_string($secret) && $secret !== '';
    }

    private function hasRecoveryCodes(mixed $recoveryCodes): bool
    {
        if (is_array($recoveryCodes)) {
            return count($recoveryCodes) > 0;
        }

        if (! is_string($recoveryCodes) || $recoveryCodes === '') {
            return false;
        }

        $decoded = json_decode($recoveryCodes, true);

        return is_array($decoded) && count($decoded) > 0;
    }
}
