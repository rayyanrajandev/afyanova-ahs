<?php

namespace App\Modules\Appointment\Application\Exceptions;

use RuntimeException;

class InvalidAppointmentStatusTransitionException extends RuntimeException
{
    public function __construct(string $fromStatus, string $toStatus)
    {
        parent::__construct(
            sprintf(
                'Appointment status cannot change from %s to %s.',
                $fromStatus,
                $toStatus,
            ),
        );
    }
}
