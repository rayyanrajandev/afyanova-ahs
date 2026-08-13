<?php

namespace App\Modules\ClaimsInsurance\Application\Exceptions;

use RuntimeException;

class ClaimsInsuranceReconciliationException extends RuntimeException
{
    public function __construct(
        private readonly string $fieldName,
        string $message
    ) {
        parent::__construct($message);
    }

    public function field(): string
    {
        return $this->fieldName;
    }
}
