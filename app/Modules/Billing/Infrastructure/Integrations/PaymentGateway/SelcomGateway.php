<?php

namespace App\Modules\Billing\Infrastructure\Integrations\PaymentGateway;

use App\Modules\Billing\Domain\Integrations\PaymentGatewayInterface;
use App\Modules\Billing\Infrastructure\Integrations\DTOs\PaymentRequest;
use App\Modules\Billing\Infrastructure\Integrations\DTOs\PaymentResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SelcomGateway implements PaymentGatewayInterface
{
    private readonly string $baseUrl;

    private readonly string $apiKey;

    private readonly string $apiSecret;

    private readonly string $vendor;

    private readonly string $pin;

    private readonly string $currency;

    private readonly int $timeout;

    public function __construct()
    {
        $config = config('billing-integrations.payment_gateway.selcom');

        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.selcommobile.com', '/');
        $this->apiKey = $config['api_key'] ?? '';
        $this->apiSecret = $config['api_secret'] ?? '';
        $this->vendor = $config['vendor'] ?? '';
        $this->pin = $config['pin'] ?? '';
        $this->currency = $config['currency'] ?? 'TZS';
        $this->timeout = (int) ($config['timeout'] ?? 30);
    }

    public function collectPayment(PaymentRequest $request): PaymentResponse
    {
        $transid = 'INV-'.Str::random(12);

        $payload = [
            'vendor' => $this->vendor,
            'pin' => $this->pin,
            'transid' => $transid,
            'reference' => $request->reference,
            'amount' => $request->amount,
            'msisdn' => $this->normalizePhone($request->phoneNumber),
            'currency' => $request->currencyCode ?: $this->currency,
            'description' => $request->description,
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->buildHeaders())
                ->post("{$this->baseUrl}/v1/payments", $payload);

            $body = $response->json();

            if ($response->successful() && ($body['resultcode'] ?? '') === '000') {
                return new PaymentResponse(
                    success: true,
                    transactionReference: $transid,
                    message: $body['message'] ?? 'Payment initiated successfully',
                    providerReference: $body['reference'] ?? null,
                    amount: (float) ($body['amount'] ?? $request->amount),
                    status: 'pending',
                    rawResponse: $body,
                );
            }

            return new PaymentResponse(
                success: false,
                transactionReference: $transid,
                message: $body['message'] ?? 'Payment initiation failed',
                providerReference: $body['reference'] ?? null,
                status: 'failed',
                rawResponse: $body,
            );
        } catch (\Throwable $e) {
            return new PaymentResponse(
                success: false,
                transactionReference: $transid,
                message: $e->getMessage(),
                status: 'error',
            );
        }
    }

    public function checkTransactionStatus(string $transactionReference): PaymentResponse
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->buildHeaders())
                ->get("{$this->baseUrl}/v1/payments/status", [
                    'vendor' => $this->vendor,
                    'pin' => $this->pin,
                    'transid' => $transactionReference,
                ]);

            $body = $response->json();

            if ($response->successful()) {
                $isSuccess = ($body['resultcode'] ?? '') === '000';

                return new PaymentResponse(
                    success: $isSuccess,
                    transactionReference: $transactionReference,
                    message: $body['message'] ?? 'Status retrieved',
                    providerReference: $body['reference'] ?? null,
                    amount: isset($body['amount']) ? (float) $body['amount'] : null,
                    status: $isSuccess ? 'completed' : 'failed',
                    rawResponse: $body,
                );
            }

            return new PaymentResponse(
                success: false,
                transactionReference: $transactionReference,
                message: 'Status check failed',
                status: 'error',
                rawResponse: $body,
            );
        } catch (\Throwable $e) {
            return new PaymentResponse(
                success: false,
                transactionReference: $transactionReference,
                message: $e->getMessage(),
                status: 'error',
            );
        }
    }

    public function refundTransaction(string $transactionReference, float $amount): PaymentResponse
    {
        $transid = 'REF-'.Str::random(12);

        $payload = [
            'vendor' => $this->vendor,
            'pin' => $this->pin,
            'transid' => $transid,
            'reference' => $transactionReference,
            'amount' => (string) $amount,
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->buildHeaders())
                ->post("{$this->baseUrl}/v1/payments/reversal", $payload);

            $body = $response->json();

            if ($response->successful() && ($body['resultcode'] ?? '') === '000') {
                return new PaymentResponse(
                    success: true,
                    transactionReference: $transid,
                    message: $body['message'] ?? 'Refund initiated',
                    providerReference: $body['reference'] ?? null,
                    amount: $amount,
                    status: 'refunded',
                    rawResponse: $body,
                );
            }

            return new PaymentResponse(
                success: false,
                transactionReference: $transid,
                message: $body['message'] ?? 'Refund failed',
                status: 'failed',
                rawResponse: $body,
            );
        } catch (\Throwable $e) {
            return new PaymentResponse(
                success: false,
                transactionReference: $transid,
                message: $e->getMessage(),
                status: 'error',
            );
        }
    }

    private function buildHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.base64_encode("{$this->apiKey}:{$this->apiSecret}"),
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($cleaned) === 9) {
            return '255'.$cleaned;
        }

        if (strlen($cleaned) === 10 && str_starts_with($cleaned, '0')) {
            return '255'.substr($cleaned, 1);
        }

        return $cleaned;
    }
}
