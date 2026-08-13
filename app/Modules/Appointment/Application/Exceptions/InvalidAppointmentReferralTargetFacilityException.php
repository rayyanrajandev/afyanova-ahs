<?php

namespace App\Modules\Appointment\Application\Exceptions;

use RuntimeException;

class InvalidAppointmentReferralTargetFacilityException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Appointment referral target facility is invalid.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}

