<?php

namespace App\Modules\Encounter\Application\Exceptions;

use RuntimeException;

class EncounterCloseBlockedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $readiness
     */
    public function __construct(
        string $message,
        private readonly array $readiness,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function readiness(): array
    {
        return $this->readiness;
    }

    public function requiresAcknowledgement(): bool
    {
        return (bool) ($this->readiness['requiresAcknowledgement'] ?? false);
    }
}
