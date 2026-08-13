<?php

namespace App\Modules\Billing\Infrastructure\Integrations\DTOs;

class NhifVerificationResult
{
    public function __construct(
        public readonly bool $isActive,
        public readonly string $memberId,
        public readonly string $memberName,
        public readonly string $cardStatus,
        public readonly ?string $employerName = null,
        public readonly ?string $planName = null,
        public readonly ?string $effectiveDate = null,
        public readonly ?string $expiryDate = null,
        public readonly ?float $outstandingBalance = null,
        public readonly ?array $dependants = null,
        public readonly ?string $remarks = null,
        public readonly ?array $rawResponse = null,
    ) {}
}
