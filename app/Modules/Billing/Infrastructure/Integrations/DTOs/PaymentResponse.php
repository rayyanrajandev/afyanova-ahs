<?php

namespace App\Modules\Billing\Infrastructure\Integrations\DTOs;

class PaymentResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $transactionReference,
        public readonly string $message,
        public readonly ?string $providerReference = null,
        public readonly ?float $amount = null,
        public readonly ?string $status = null,
        public readonly ?array $rawResponse = null,
    ) {}
}
