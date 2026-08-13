<?php

namespace App\Modules\MedicalRecord\Application\Exceptions;

use RuntimeException;

class InvalidMedicalRecordStatusTransitionException extends RuntimeException
{
    public function __construct(string $fromStatus, string $toStatus)
    {
        parent::__construct(
            sprintf(
                'Medical record status cannot change from %s to %s.',
                $fromStatus,
                $toStatus,
            ),
        );
    }
}
