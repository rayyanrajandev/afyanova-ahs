<?php

namespace App\Modules\Encounter\Application\Exceptions;

use RuntimeException;

class InvalidEncounterStatusTransitionException extends RuntimeException
{
    public function __construct(string $fromStatus, string $toStatus)
    {
        parent::__construct(
            sprintf(
                'Encounter status cannot change from %s to %s.',
                $fromStatus,
                $toStatus,
            ),
        );
    }
}
