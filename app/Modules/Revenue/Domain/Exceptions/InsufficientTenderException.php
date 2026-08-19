<?php

namespace App\Modules\Revenue\Domain\Exceptions;

use App\Modules\Revenue\Domain\ValueObjects\Money;
use RuntimeException;

/**
 * Prepaid means paid. A part payment would leave the service unprovided and
 * the patient believing otherwise, so the counter rejects it outright rather
 * than recording a balance nobody will chase.
 */
class InsufficientTenderException extends RuntimeException
{
    public function __construct(
        public readonly Money $due,
        public readonly Money $tendered,
    ) {
        parent::__construct(sprintf(
            'Tendered %s %s does not cover the %s %s due.',
            $tendered->currencyCode,
            $tendered->toDecimalString(),
            $due->currencyCode,
            $due->toDecimalString(),
        ));
    }
}
