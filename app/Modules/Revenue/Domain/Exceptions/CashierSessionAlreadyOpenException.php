<?php

namespace App\Modules\Revenue\Domain\Exceptions;

use RuntimeException;

class CashierSessionAlreadyOpenException extends RuntimeException
{
    public function __construct(public readonly string $sessionNumber)
    {
        parent::__construct(sprintf(
            'You already have drawer %s open. Close it before opening another.',
            $sessionNumber,
        ));
    }
}
