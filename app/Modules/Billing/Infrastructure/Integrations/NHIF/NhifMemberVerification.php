<?php

namespace App\Modules\Billing\Infrastructure\Integrations\NHIF;

use App\Modules\Billing\Domain\Integrations\NhifVerificationInterface;
use App\Modules\Billing\Infrastructure\Integrations\DTOs\NhifVerificationResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class NhifMemberVerification implements NhifVerificationInterface
{
    private readonly string $baseUrl;

    private readonly string $clientId;

    private readonly string $clientSecret;

    private readonly string $scope;

    private readonly int $timeout;

    private ?string $accessToken = null;

    private ?int $tokenExpiresAt = null;

    public function __construct()
    {
        $config = config('billing-integrations.nhif');

        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.nhif.or.tz', '/');
        $this->clientId = $config['client_id'] ?? '';
        $this->clientSecret = $config['client_secret'] ?? '';
        $this->scope = $config['scope'] ?? 'OMRS';
        $this->timeout = (int) ($config['timeout'] ?? 15);
    }

    public function verifyMember(string $memberId): ?array
    {
        $token = $this->getAccessToken();
        if ($token === null) {
            return null;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withToken($token)
                ->get("{$this->baseUrl}/omrs/api/v1/Verification/GetMemberDetails", [
                    'MemberID' => $memberId,
                ]);

            if ($response->successful()) {
                $body = $response->json();

                return [
                    'is_active' => ($body['CardStatus'] ?? '') === 'Active',
                    'member_id' => $memberId,
                    'member_name' => $body['FullName'] ?? null,
                    'card_status' => $body['CardStatus'] ?? 'Unknown',
                    'plan_name' => $body['PlanName'] ?? null,
                    'employer_name' => $body['EmployerName'] ?? null,
                    'effective_date' => $body['EffectiveDate'] ?? null,
                    'expiry_date' => $body['ExpiryDate'] ?? null,
                    'outstanding_balance' => isset($body['OutstandingBalance']) ? (float) $body['OutstandingBalance'] : null,
                    'dependants' => $body['Dependants'] ?? null,
                    'raw_response' => $body,
                ];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function checkCardStatus(string $cardNumber): ?array
    {
        $token = $this->getAccessToken();
        if ($token === null) {
            return null;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withToken($token)
                ->get("{$this->baseUrl}/omrs/api/v1/Verification/GetCardStatus", [
                    'CardNumber' => $cardNumber,
                ]);

            if ($response->successful()) {
                $body = $response->json();

                return [
                    'is_active' => ($body['Status'] ?? '') === 'Active',
                    'card_number' => $cardNumber,
                    'card_status' => $body['Status'] ?? 'Unknown',
                    'member_id' => $body['MemberID'] ?? null,
                    'member_name' => $body['FullName'] ?? null,
                    'remarks' => $body['Remarks'] ?? null,
                    'raw_response' => $body,
                ];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getMemberDetails(string $memberId): ?array
    {
        return $this->verifyMember($memberId);
    }

    private function getAccessToken(): ?string
    {
        if ($this->accessToken !== null && $this->tokenExpiresAt !== null && now()->timestamp < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        $cacheKey = 'nhif_access_token';

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            $this->accessToken = $cached;

            return $this->accessToken;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->asForm()
                ->post("{$this->baseUrl}/omrs/stsidentity", [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => $this->scope,
                ]);

            if ($response->successful()) {
                $body = $response->json();
                $this->accessToken = $body['access_token'] ?? null;
                $expiresIn = (int) ($body['expires_in'] ?? 3600);

                if ($this->accessToken) {
                    $this->tokenExpiresAt = now()->timestamp + $expiresIn - 60;
                    Cache::put($cacheKey, $this->accessToken, now()->addSeconds($expiresIn - 60));

                    return $this->accessToken;
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
