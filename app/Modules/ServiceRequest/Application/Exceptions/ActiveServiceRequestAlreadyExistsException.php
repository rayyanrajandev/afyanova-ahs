<?php

namespace App\Modules\ServiceRequest\Application\Exceptions;

use RuntimeException;

class ActiveServiceRequestAlreadyExistsException extends RuntimeException
{
    public function __construct(private readonly array $existingRequest)
    {
        parent::__construct('An active direct service ticket already exists for this patient and service desk.');
    }

    /**
     * @return array<string, mixed>
     */
    public function existingRequest(): array
    {
        return $this->existingRequest;
    }
}
