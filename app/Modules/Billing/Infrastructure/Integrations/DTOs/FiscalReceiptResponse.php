<?php

namespace App\Modules\Billing\Infrastructure\Integrations\DTOs;

class FiscalReceiptResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $rctvnum,
        public readonly string $verificationLink,
        public readonly string $localDate,
        public readonly string $localTime,
        public readonly int $gc,
        public readonly int $dc,
        public readonly string $zNumber,
        public readonly string $message,
        public readonly ?array $totals = null,
        public readonly ?array $vat = null,
        public readonly ?array $rawResponse = null,
    ) {}
}
