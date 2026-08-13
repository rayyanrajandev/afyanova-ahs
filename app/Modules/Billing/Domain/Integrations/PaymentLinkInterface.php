<?php

namespace App\Modules\Billing\Domain\Integrations;

use App\Modules\Billing\Domain\ValueObjects\PaymentLinkResult;

interface PaymentLinkInterface
{
    public function generatePaymentLink(
        array $payload,
        string $phoneNumber,
        float $amount,
        string $referenceCode,
    ): PaymentLinkResult;

    public function checkPaymentStatus(string $referenceCode): PaymentLinkResult;
}
