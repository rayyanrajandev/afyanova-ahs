<?php

namespace App\Modules\Revenue\Domain\Exceptions;

use RuntimeException;

/**
 * Cash cannot be taken without an open drawer to put it in. Distinct from a
 * generic validation error so the workspace can offer "Open your drawer"
 * instead of a message the cashier cannot act on.
 */
class CashierSessionRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Open your drawer before taking payment.');
    }
}
