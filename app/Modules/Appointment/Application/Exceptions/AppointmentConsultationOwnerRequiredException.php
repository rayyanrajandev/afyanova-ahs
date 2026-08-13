<?php

namespace App\Modules\Appointment\Application\Exceptions;

use RuntimeException;

class AppointmentConsultationOwnerRequiredException extends RuntimeException
{
    public function __construct(public readonly int $ownerUserId)
    {
        parent::__construct(
            'Only the consultation owner or a facility administrator can change this visit\'s status while it is in consultation.',
        );
    }
}
