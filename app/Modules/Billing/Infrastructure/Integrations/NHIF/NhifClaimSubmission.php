<?php

namespace App\Modules\Billing\Infrastructure\Integrations\NHIF;

use App\Modules\Billing\Domain\Integrations\NhifClaimSubmissionInterface;
use App\Modules\Billing\Domain\ValueObjects\NhifClaimSubmissionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NhifClaimSubmission implements NhifClaimSubmissionInterface
{
    private ?string $accessToken = null;

    private function authenticate(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $baseUrl = config('billing-integrations.nhif.base_url');
        $clientId = config('billing-integrations.nhif.client_id');
        $clientSecret = config('billing-integrations.nhif.client_secret');

        $response = Http::asForm()->post("{$baseUrl}/auth/token", [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if (!$response->successful()) {
            Log::error('NHIF claim auth failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to authenticate with NHIF');
        }

        $this->accessToken = $response->json('access_token');
        return $this->accessToken;
    }

    public function submitClaim(
        string $memberNumber,
        string $authorizationNumber,
        array $claimItems,
        float $totalAmount,
        ?string $claimReference = null,
    ): NhifClaimSubmissionResult {
        try {
            $token = $this->authenticate();
            $baseUrl = config('billing-integrations.nhif.base_url');
            $facilityCode = config('billing-integrations.nhif.facility_code');

            $payload = [
                'facilityCode' => $facilityCode,
                'memberNumber' => $memberNumber,
                'authorizationNumber' => $authorizationNumber,
                'claimReference' => $claimReference ?? uniqid('CLM-', true),
                'claimDate' => now()->format('Y-m-d'),
                'totalAmount' => $totalAmount,
                'items' => $claimItems,
            ];

            $response = Http::withToken($token)
                ->timeout(60)
                ->post("{$baseUrl}/claims/submit", $payload);

            $body = $response->json();

            if ($response->successful()) {
                return new NhifClaimSubmissionResult(
                    success: true,
                    claimReference: $body['claimReference'] ?? $payload['claimReference'],
                    submissionStatus: $body['status'] ?? 'submitted',
                    message: $body['message'] ?? 'Claim submitted successfully',
                    rawPayload: $payload,
                    rawResponse: $body,
                );
            }

            Log::warning('NHIF claim submission failed', [
                'status' => $response->status(),
                'body' => $body,
            ]);

            return new NhifClaimSubmissionResult(
                success: false,
                claimReference: $payload['claimReference'],
                submissionStatus: 'rejected',
                message: $body['message'] ?? 'Claim submission rejected',
                rawPayload: $payload,
                rawResponse: $body,
                errorCode: (string)$response->status(),
            );
        } catch (\Throwable $e) {
            Log::error('NHIF claim submission exception', [
                'error' => $e->getMessage(),
                'member' => $memberNumber,
            ]);

            return new NhifClaimSubmissionResult(
                success: false,
                claimReference: $claimReference,
                submissionStatus: 'failed',
                message: $e->getMessage(),
                rawPayload: compact('memberNumber', 'authorizationNumber', 'claimItems', 'totalAmount'),
            );
        }
    }

    public function checkClaimStatus(string $claimReference): NhifClaimSubmissionResult
    {
        try {
            $token = $this->authenticate();
            $baseUrl = config('billing-integrations.nhif.base_url');
            $facilityCode = config('billing-integrations.nhif.facility_code');

            $response = Http::withToken($token)
                ->timeout(30)
                ->get("{$baseUrl}/claims/status", [
                    'claimReference' => $claimReference,
                    'facilityCode' => $facilityCode,
                ]);

            $body = $response->json();

            if ($response->successful()) {
                return new NhifClaimSubmissionResult(
                    success: true,
                    claimReference: $claimReference,
                    submissionStatus: $body['status'] ?? 'unknown',
                    message: $body['message'] ?? 'Status retrieved',
                    rawResponse: $body,
                );
            }

            return new NhifClaimSubmissionResult(
                success: false,
                claimReference: $claimReference,
                submissionStatus: 'error',
                message: $body['message'] ?? 'Failed to retrieve status',
                rawResponse: $body,
                errorCode: (string)$response->status(),
            );
        } catch (\Throwable $e) {
            return new NhifClaimSubmissionResult(
                success: false,
                claimReference: $claimReference,
                submissionStatus: 'error',
                message: $e->getMessage(),
            );
        }
    }
}
