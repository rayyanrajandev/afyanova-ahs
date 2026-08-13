<?php

namespace App\Modules\Billing\Infrastructure\Integrations\PaymentGateway;

use App\Modules\Billing\Domain\Integrations\PaymentLinkInterface;
use App\Modules\Billing\Domain\ValueObjects\PaymentLinkResult;
use App\Modules\Billing\Infrastructure\Integrations\DTOs\PaymentRequest;
use Illuminate\Support\Str;

class SelcomPaymentLinkService implements PaymentLinkInterface
{
    public function __construct(
        private readonly SelcomGateway $gateway = new SelcomGateway,
    ) {}

    public function generatePaymentLink(
        array $payload,
        string $phoneNumber,
        float $amount,
        string $referenceCode,
    ): PaymentLinkResult {
        $request = new PaymentRequest(
            reference: $referenceCode,
            amount: (string) $amount,
            phoneNumber: $phoneNumber,
            description: $payload['description'] ?? 'Payment for invoice '.($payload['invoice_number'] ?? ''),
            currencyCode: $payload['currency'] ?? 'TZS',
        );

        $response = $this->gateway->collectPayment($request);

        if ($response->success) {
            return new PaymentLinkResult(
                success: true,
                referenceCode: $referenceCode,
                providerReference: $response->providerReference,
                gatewayTransactionId: $response->transactionReference,
                status: $response->status,
                message: $response->message,
                rawResponse: $response->rawResponse,
            );
        }

        return new PaymentLinkResult(
            success: false,
            referenceCode: $referenceCode,
            message: $response->message,
            status: $response->status,
            rawResponse: $response->rawResponse,
        );
    }

    public function checkPaymentStatus(string $referenceCode): PaymentLinkResult
    {
        $response = $this->gateway->checkTransactionStatus($referenceCode);

        return new PaymentLinkResult(
            success: $response->success,
            referenceCode: $referenceCode,
            providerReference: $response->providerReference,
            gatewayTransactionId: $response->transactionReference,
            status: $response->status,
            message: $response->message,
            rawResponse: $response->rawResponse,
        );
    }
}
