<?php

namespace App\Modules\Encounter\Application\Exceptions;

use RuntimeException;

class EncounterOwnerConflictException extends RuntimeException
{
    public function __construct(public readonly int $ownerUserId)
    {
        parent::__construct(
            'Only the encounter\'s primary clinician or a facility administrator can close or reopen this encounter.',
        );
    }
}
