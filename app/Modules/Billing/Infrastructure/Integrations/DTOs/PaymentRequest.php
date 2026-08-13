<?php

namespace App\Modules\Billing\Infrastructure\Integrations\DTOs;

class PaymentRequest
{
    public function __construct(
        public readonly string $amount,
        public readonly string $currencyCode,
        public readonly string $phoneNumber,
        public readonly string $reference,
        public readonly string $description,
        public readonly ?string $customerName = null,
        public readonly ?string $customerEmail = null,
    ) {}
}
