<?php

namespace App\Modules\Billing\Application\Exceptions;

use RuntimeException;

class BillingInvoicePricingResolutionException extends RuntimeException
{
    public function __construct(
        private readonly string $field,
        string $message
    ) {
        parent::__construct($message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
