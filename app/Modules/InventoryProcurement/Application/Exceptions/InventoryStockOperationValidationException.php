<?php

namespace App\Modules\InventoryProcurement\Application\Exceptions;

use RuntimeException;

class InventoryStockOperationValidationException extends RuntimeException
{
    public function __construct(
        private readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
