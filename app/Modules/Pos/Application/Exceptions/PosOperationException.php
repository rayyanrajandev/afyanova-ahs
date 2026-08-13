<?php

namespace App\Modules\Pos\Application\Exceptions;

use RuntimeException;

class PosOperationException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $field = 'general',
    ) {
        parent::__construct($message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
