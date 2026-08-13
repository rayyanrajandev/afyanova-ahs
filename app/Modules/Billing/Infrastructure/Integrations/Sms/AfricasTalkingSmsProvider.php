<?php

namespace App\Modules\Billing\Infrastructure\Integrations\Sms;

use App\Modules\Billing\Domain\Integrations\SmsProviderInterface;
use App\Modules\Billing\Domain\ValueObjects\SmsResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AfricasTalkingSmsProvider implements SmsProviderInterface
{
    private readonly string $username;

    private readonly string $apiKey;

    private readonly string $from;

    private readonly string $baseUrl;

    public function __construct()
    {
        $config = config('billing-integrations.sms.africastalking');

        $this->username = $config['username'] ?? '';
        $this->apiKey = $config['api_key'] ?? '';
        $this->from = $config['from'] ?? '';
        $this->baseUrl = $config['base_url'] ?? 'https://api.africastalking.com';
    }

    public function send(string $phoneNumber, string $message, array $options = []): SmsResult
    {
        $normalized = $this->normalizePhone($phoneNumber);

        $payload = [
            'username' => $this->username,
            'to' => $normalized,
            'message' => $message,
        ];

        if ($this->from) {
            $payload['from'] = $this->from;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'apiKey' => $this->apiKey,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ])
                ->asForm()
                ->post("{$this->baseUrl}/version1/messaging", $payload);

            $body = $response->json();

            if ($response->successful() && ($body['SMSMessageData']['Recipients'][0]['status'] ?? '') === 'Success') {
                return new SmsResult(
                    success: true,
                    phoneNumber: $normalized,
                    providerMessageId: $body['SMSMessageData']['Recipients'][0]['messageId'] ?? null,
                    status: 'sent',
                    message: 'SMS sent successfully',
                    rawResponse: $body,
                );
            }

            Log::warning('SMS send failed', [
                'phone' => $normalized,
                'status' => $response->status(),
                'body' => $body,
            ]);

            return new SmsResult(
                success: false,
                phoneNumber: $normalized,
                status: 'failed',
                message: $body['SMSMessageData']['Recipients'][0]['status'] ?? 'SMS sending failed',
                rawResponse: $body,
            );
        } catch (\Throwable $e) {
            Log::error('SMS send exception', [
                'phone' => $normalized,
                'error' => $e->getMessage(),
            ]);

            return new SmsResult(
                success: false,
                phoneNumber: $normalized,
                status: 'error',
                message: $e->getMessage(),
            );
        }
    }

    private function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($cleaned) === 9) {
            return '255' . $cleaned;
        }

        if (strlen($cleaned) === 10 && str_starts_with($cleaned, '0')) {
            return '255' . substr($cleaned, 1);
        }

        return $cleaned;
    }
}
