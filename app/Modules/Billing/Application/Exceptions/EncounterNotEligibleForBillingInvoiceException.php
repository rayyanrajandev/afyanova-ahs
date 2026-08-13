<?php

namespace App\Modules\Billing\Application\Exceptions;

use RuntimeException;

class EncounterNotEligibleForBillingInvoiceException extends RuntimeException
{
    public function __construct(string $message = 'Encounter is not valid for the selected patient.')
    {
        parent::__construct($message);
    }
}
