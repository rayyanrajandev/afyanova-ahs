<?php

namespace App\Modules\MedicalRecord\Application\Exceptions;

use RuntimeException;

class ConsultationOwnerConflictForMedicalRecordException extends RuntimeException
{
    public function __construct(
        private readonly int $ownerUserId,
        string $message = 'This consultation is currently owned by another clinician. Confirm takeover to continue.',
    ) {
        parent::__construct($message);
    }

    public function ownerUserId(): int
    {
        return $this->ownerUserId;
    }
}

