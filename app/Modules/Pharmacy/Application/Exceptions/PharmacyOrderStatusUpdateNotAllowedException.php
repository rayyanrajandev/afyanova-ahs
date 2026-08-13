<?php

namespace App\Modules\Pharmacy\Application\Exceptions;

use RuntimeException;

class PharmacyOrderStatusUpdateNotAllowedException extends RuntimeException
{
    public function __construct(string $message, private readonly string $field = 'status')
    {
        parent::__construct($message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
