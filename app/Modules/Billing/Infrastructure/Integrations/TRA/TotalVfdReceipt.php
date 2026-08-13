<?php

namespace App\Modules\Billing\Infrastructure\Integrations\TRA;

use App\Modules\Billing\Domain\Integrations\TraFiscalReceiptInterface;
use App\Modules\Billing\Infrastructure\Integrations\DTOs\FiscalReceiptRequest;
use App\Modules\Billing\Infrastructure\Integrations\DTOs\FiscalReceiptResponse;
use Illuminate\Support\Facades\Http;

class TotalVfdReceipt implements TraFiscalReceiptInterface
{
    private readonly string $baseUrl;

    private readonly string $apiKey;

    private readonly string $apiSecret;

    private readonly string $tin;

    private readonly string $vrn;

    private readonly string $businessName;

    private readonly string $businessStreet;

    private readonly string $businessCity;

    private readonly string $businessMobile;

    private readonly string $efdSerial;

    private readonly string $taxOffice;

    private readonly string $currency;

    private readonly int $timeout;

    private ?string $authToken = null;

    private ?string $activeBusinessId = null;

    public function __construct()
    {
        $config = config('billing-integrations.tra_vfd.totalvfd');

        $this->baseUrl = rtrim($config['base_url'] ?? 'https://testapi.totalvfd.co.tz', '/');
        $this->apiKey = $config['api_key'] ?? '';
        $this->apiSecret = $config['api_secret'] ?? '';
        $this->tin = $config['tin'] ?? '';
        $this->vrn = $config['vrn'] ?? '';
        $this->businessName = $config['business_name'] ?? '';
        $this->businessStreet = $config['business_street'] ?? '';
        $this->businessCity = $config['business_city'] ?? 'Dar Es Salaam';
        $this->businessMobile = $config['business_mobile'] ?? '';
        $this->efdSerial = $config['efd_serial'] ?? '';
        $this->taxOffice = $config['tax_office'] ?? '';
        $this->currency = $config['currency'] ?? 'TZS';
        $this->timeout = (int) ($config['timeout'] ?? 30);
    }

    public function issueReceipt(FiscalReceiptRequest $request): FiscalReceiptResponse
    {
        $this->ensureAuthenticated();

        $lineItems = [];
        foreach ($request->lineItems as $item) {
            $lineItems[] = [
                'description' => $item['description'] ?? 'Service',
                'quantity' => (float) ($item['quantity'] ?? 1),
                'unitPrice' => (float) ($item['unitPrice'] ?? 0),
                'total' => (float) ($item['lineTotal'] ?? 0),
                'vatGroup' => $item['vatGroup'] ?? 'A',
            ];
        }

        $payload = [
            'referenceNumber' => $request->referenceNumber,
            'tin' => $this->tin,
            'vrn' => $this->vrn,
            'customer' => [
                'name' => $request->customerName ?? 'Walk-in Customer',
                'idType' => $request->customerIdType ?? '6',
                'idNumber' => $request->customerId ?? '',
                'mobile' => $request->customerMobile ?? '',
            ],
            'currency' => $this->currency,
            'lineItems' => $lineItems,
            'totals' => [
                'totalExclTax' => $request->totalExclTax,
                'totalTax' => $request->totalTax,
                'totalInclTax' => $request->totalInclTax,
            ],
            'payments' => [
                [
                    'method' => $request->paymentMethod,
                    'amount' => $request->totalInclTax,
                ],
            ],
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->authHeaders())
                ->post("{$this->baseUrl}/api/sales", $payload);

            $body = $response->json();

            if ($response->successful() && ($body['status'] ?? '') === 'success') {
                $data = $body['data'] ?? $body;

                return new FiscalReceiptResponse(
                    success: true,
                    rctvnum: $data['rctvnum'] ?? '',
                    verificationLink: $data['verificationLink'] ?? '',
                    localDate: $data['localDate'] ?? now()->format('Y-m-d'),
                    localTime: $data['localTime'] ?? now()->format('H:i:s'),
                    gc: (int) ($data['gc'] ?? 0),
                    dc: (int) ($data['dc'] ?? 0),
                    zNumber: $data['zNumber'] ?? now()->format('Ymd'),
                    message: 'Receipt issued successfully',
                    totals: $data['totals'] ?? null,
                    vat: $data['vat'] ?? null,
                    rawResponse: $body,
                );
            }

            if ($response->status() === 409) {
                $existing = $body['data'] ?? null;
                if ($existing) {
                    return new FiscalReceiptResponse(
                        success: true,
                        rctvnum: $existing['rctvnum'] ?? '',
                        verificationLink: $existing['verificationLink'] ?? '',
                        localDate: $existing['localDate'] ?? '',
                        localTime: $existing['localTime'] ?? '',
                        gc: (int) ($existing['gc'] ?? 0),
                        dc: (int) ($existing['dc'] ?? 0),
                        zNumber: $existing['zNumber'] ?? '',
                        message: 'Receipt already exists (duplicate reference)',
                        totals: $existing['totals'] ?? null,
                        vat: $existing['vat'] ?? null,
                        rawResponse: $body,
                    );
                }
            }

            return new FiscalReceiptResponse(
                success: false,
                rctvnum: '',
                verificationLink: '',
                localDate: '',
                localTime: '',
                gc: 0,
                dc: 0,
                zNumber: '',
                message: $body['message'] ?? 'Failed to issue receipt',
                rawResponse: $body,
            );
        } catch (\Throwable $e) {
            return new FiscalReceiptResponse(
                success: false,
                rctvnum: '',
                verificationLink: '',
                localDate: '',
                localTime: '',
                gc: 0,
                dc: 0,
                zNumber: '',
                message: $e->getMessage(),
            );
        }
    }

    public function submitZReport(\DateTimeInterface $date): ?array
    {
        $this->ensureAuthenticated();

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->authHeaders())
                ->post("{$this->baseUrl}/api/z-report", [
                    'date' => $date->format('Y-m-d'),
                    'tin' => $this->tin,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function verifyReceipt(string $rctvnum): ?array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->get("{$this->baseUrl}/api/sales/verify", [
                    'rctvnum' => $rctvnum,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureAuthenticated(): void
    {
        if ($this->authToken !== null) {
            return;
        }

        $response = Http::timeout($this->timeout)
            ->post("{$this->baseUrl}/api/login", [
                'apiKey' => $this->apiKey,
                'apiSecret' => $this->apiSecret,
            ]);

        if ($response->successful()) {
            $body = $response->json();
            $this->authToken = $body['token'] ?? $body['access_token'] ?? null;
            $this->activeBusinessId = $body['business']['_id'] ?? $body['activeBusiness'] ?? null;
        }
    }

    private function authHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($this->authToken) {
            $headers['Authorization'] = 'Bearer '.$this->authToken;
        }

        if ($this->activeBusinessId) {
            $headers['x-active-business'] = $this->activeBusinessId;
        }

        return $headers;
    }
}
