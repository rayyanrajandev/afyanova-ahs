<?php

namespace App\Modules\Billing\Domain\ValueObjects;

class PaymentLinkResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $referenceCode,
        public readonly ?string $providerReference = null,
        public readonly ?string $gatewayTransactionId = null,
        public readonly ?string $status = null,
        public readonly ?string $message = null,
        public readonly ?array $rawResponse = null,
    ) {}
}
