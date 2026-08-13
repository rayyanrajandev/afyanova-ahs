<?php

namespace App\Modules\Billing\Domain\ValueObjects;

class NhifRemittanceResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $remittanceReference,
        public readonly int $totalClaims = 0,
        public readonly int $matchedClaims = 0,
        public readonly float $totalAmount = 0,
        public readonly float $matchedAmount = 0,
        public readonly float $unmatchedAmount = 0,
        public readonly ?string $message = null,
        public readonly ?array $rawData = null,
        public readonly ?array $errors = null,
    ) {}
}
