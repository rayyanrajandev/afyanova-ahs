<?php

namespace App\Modules\Staff\Application\Services;

use App\Modules\Staff\Application\Exceptions\UnverifiedStaffUserEmailException;

class VerifiedStaffUserEmailGuard
{
    /**
     * @param  array<string, mixed>  $staffProfile
     */
    public function assertVerified(array $staffProfile): void
    {
        if (($staffProfile['user_id'] ?? null) === null) {
            throw new UnverifiedStaffUserEmailException(
                'This staff profile must be linked to a verified user account before sensitive staff governance actions can continue.',
            );
        }

        if (($staffProfile['user_email_verified_at'] ?? null) !== null) {
            return;
        }

        $email = trim((string) ($staffProfile['user_email'] ?? ''));
        if ($email !== '') {
            throw new UnverifiedStaffUserEmailException(sprintf(
                'Linked user email %s is not verified. Sensitive credentialing and privileging actions stay blocked until the user completes the invite or verification flow.',
                $email,
            ));
        }

        throw new UnverifiedStaffUserEmailException(
            'Linked user email is not verified. Sensitive credentialing and privileging actions stay blocked until the user completes the invite or verification flow.',
        );
    }
}
