<?php

namespace App\Modules\Billing\Application\Exceptions;

use RuntimeException;

class DuplicatePatientInsuranceMemberException extends RuntimeException
{
    /**
     * @param array<int, array<string, mixed>> $duplicates
     */
    public function __construct(private readonly array $duplicates)
    {
        parent::__construct('Duplicate patient insurance member ID found.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDuplicates(): array
    {
        return $this->duplicates;
    }
}
