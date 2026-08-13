<?php

namespace App\Modules\Billing\Domain\Integrations;

use App\Modules\Billing\Infrastructure\Integrations\DTOs\PaymentRequest;
use App\Modules\Billing\Infrastructure\Integrations\DTOs\PaymentResponse;

interface PaymentGatewayInterface
{
    public function collectPayment(PaymentRequest $request): PaymentResponse;

    public function checkTransactionStatus(string $transactionReference): PaymentResponse;

    public function refundTransaction(string $transactionReference, float $amount): PaymentResponse;
}
