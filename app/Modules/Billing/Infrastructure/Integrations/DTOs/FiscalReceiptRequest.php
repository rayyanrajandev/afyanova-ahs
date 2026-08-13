<?php

namespace App\Modules\Billing\Infrastructure\Integrations\DTOs;

class FiscalReceiptRequest
{
    public function __construct(
        public readonly string $referenceNumber,
        public readonly string $tin,
        public readonly string $businessName,
        public readonly string $businessLocation,
        public readonly array $lineItems,
        public readonly float $totalExclTax,
        public readonly float $totalTax,
        public readonly float $totalInclTax,
        public readonly string $paymentMethod,
        public readonly ?string $customerName = null,
        public readonly ?string $customerIdType = null,
        public readonly ?string $customerId = null,
        public readonly ?string $customerMobile = null,
        public readonly ?string $invoiceNumber = null,
    ) {}
}
