<?php

namespace App\Modules\Appointment\Application\Exceptions;

use RuntimeException;

class ActiveAppointmentConflictException extends RuntimeException
{
    /**
     * @param array<string, mixed> $existingAppointment
     */
    public function __construct(
        private readonly array $existingAppointment,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function existingAppointment(): array
    {
        return $this->existingAppointment;
    }
}
