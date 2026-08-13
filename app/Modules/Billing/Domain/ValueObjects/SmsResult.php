<?php

namespace App\Modules\Billing\Domain\ValueObjects;

class SmsResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $phoneNumber,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $status = null,
        public readonly ?string $message = null,
        public readonly ?array $rawResponse = null,
    ) {}
}
