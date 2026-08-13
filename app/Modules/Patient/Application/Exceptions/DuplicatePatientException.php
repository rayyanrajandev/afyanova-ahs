<?php

namespace App\Modules\Patient\Application\Exceptions;

use RuntimeException;

class DuplicatePatientException extends RuntimeException
{
    /**
     * @param array<int, array<string, mixed>> $duplicates
     */
    public function __construct(private readonly array $duplicates)
    {
        parent::__construct('Duplicate active patient record(s) found.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDuplicates(): array
    {
        return $this->duplicates;
    }
}
