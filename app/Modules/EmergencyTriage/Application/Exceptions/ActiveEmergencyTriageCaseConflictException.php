<?php

namespace App\Modules\EmergencyTriage\Application\Exceptions;

use RuntimeException;

class ActiveEmergencyTriageCaseConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $existingCase
     */
    public function __construct(
        private readonly array $existingCase,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function existingCase(): array
    {
        return $this->existingCase;
    }
}
