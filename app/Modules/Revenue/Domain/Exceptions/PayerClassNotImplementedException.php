<?php

namespace App\Modules\Revenue\Domain\Exceptions;

use App\Modules\Revenue\Domain\ValueObjects\PayerClass;
use RuntimeException;

/**
 * Raised when a charge is created against a payer this phase cannot settle.
 *
 * Failing here is deliberate. The ledger is payer-aware but only self-pay is
 * implemented: a charge raised against an insurer would price correctly, sit
 * at pending_payment, and never clear — stranding the patient at a counter
 * that has no way to help them. Better to refuse at the point the payer is
 * chosen, where reception can still change it.
 */
class PayerClassNotImplementedException extends RuntimeException
{
    public function __construct(public readonly PayerClass $payerClass)
    {
        parent::__construct(sprintf(
            'Charges cannot be raised for payer class "%s" yet — cash is the only settled payer '
            .'in this phase. Register the visit as self-pay, or add an authorization policy for '
            .'this class first.',
            $payerClass->value,
        ));
    }
}
