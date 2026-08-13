<?php

namespace App\Modules\Billing\Infrastructure\Integrations\NHIF;

use App\Modules\Billing\Infrastructure\Models\BillingNhifTariffImportModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class NhifTariffSyncService
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
            throw new \RuntimeException('Failed to authenticate with NHIF for tariff sync');
        }

        $this->accessToken = $response->json('access_token');
        return $this->accessToken;
    }

    public function fetchTariffSchedule(
        ?string $version = null,
        ?string $effectiveDate = null,
    ): array {
        $token = $this->authenticate();
        $baseUrl = config('billing-integrations.nhif.base_url');
        $facilityCode = config('billing-integrations.nhif.facility_code');

        $params = array_filter([
            'facilityCode' => $facilityCode,
            'version' => $version,
            'effectiveDate' => $effectiveDate ?? now()->format('Y-m-d'),
        ]);

        $response = Http::withToken($token)
            ->timeout(120)
            ->get("{$baseUrl}/tariffs", $params);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to fetch NHIF tariff schedule: '.$response->body());
        }

        return $response->json();
    }

    public function importTariffSchedule(
        string $tenantId,
        string $facilityId,
        ?string $version = null,
        ?string $effectiveDate = null,
        ?int $importedByUserId = null,
    ): BillingNhifTariffImportModel {
        $data = $this->fetchTariffSchedule($version, $effectiveDate);

        $tariffItems = $data['items'] ?? $data['tariffs'] ?? $data['data'] ?? [];
        $tariffVersion = $data['version'] ?? $version ?? 'unknown';
        $effectiveDateStr = $data['effectiveDate'] ?? $effectiveDate ?? now()->toDateString();

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $log = [];

        foreach ($tariffItems as $item) {
            $nhifCode = $item['code'] ?? $item['tariffCode'] ?? null;
            $serviceName = $item['name'] ?? $item['serviceName'] ?? null;
            $price = (float) ($item['price'] ?? $item['amount'] ?? $item['tariff'] ?? 0);

            if (!$nhifCode || !$serviceName) {
                $skipped++;
                $log[] = ['action' => 'skipped', 'reason' => 'Missing code or name', 'item' => $item];
                continue;
            }

            $existing = ChargeableItemModel::query()
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('metadata->nhif_code', $nhifCode)
                ->first();

            if ($existing) {
                $metadata = is_array($existing->metadata) ? $existing->metadata : [];
                $metadata['nhif_tariff'] = $price;
                $metadata['nhif_tariff_version'] = $tariffVersion;

                $existing->update([
                    'metadata' => $metadata,
                ]);

                PriceBookEntryModel::query()
                    ->where('chargeable_item_id', $existing->id)
                    ->where('tenant_id', $tenantId)
                    ->where('facility_id', $facilityId)
                    ->whereNull('payer_contract_id')
                    ->update(['unit_price' => $price]);

                $updated++;
                $log[] = ['action' => 'updated', 'chargeable_item_id' => $existing->id, 'nhif_code' => $nhifCode];
            } else {
                $chargeableItem = new ChargeableItemModel();
                $chargeableItem->id = (string) Str::orderedUuid();
                $chargeableItem->fill([
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'catalog_type' => 'nhif_tariff',
                    'code' => $nhifCode,
                    'name' => $serviceName,
                    'default_unit' => 'service',
                    'status' => 'active',
                    'metadata' => [
                        'nhif_code' => $nhifCode,
                        'nhif_tariff' => $price,
                        'nhif_tariff_version' => $tariffVersion,
                    ],
                ]);
                $chargeableItem->save();

                $priceEntry = new PriceBookEntryModel();
                $priceEntry->id = (string) Str::orderedUuid();
                $priceEntry->chargeable_item_id = $chargeableItem->id;
                $priceEntry->tenant_id = $tenantId;
                $priceEntry->facility_id = $facilityId;
                $priceEntry->currency_code = 'TZS';
                $priceEntry->unit_price = $price;
                $priceEntry->tariff_version = 1;
                $priceEntry->status = 'active';
                $priceEntry->save();

                $imported++;
                $log[] = ['action' => 'created', 'chargeable_item_id' => $chargeableItem->id, 'nhif_code' => $nhifCode];
            }
        }

        return BillingNhifTariffImportModel::create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'tariff_version' => $tariffVersion,
            'effective_date' => $effectiveDateStr,
            'items_imported' => $imported,
            'items_updated' => $updated,
            'items_skipped' => $skipped,
            'import_log' => $log,
            'status' => 'completed',
            'imported_by_user_id' => $importedByUserId,
        ]);
    }

    public function previewTariffSchedule(
        ?string $version = null,
        ?string $effectiveDate = null,
    ): array {
        $data = $this->fetchTariffSchedule($version, $effectiveDate);

        $tariffItems = $data['items'] ?? $data['tariffs'] ?? $data['data'] ?? [];

        return [
            'version' => $data['version'] ?? $version ?? 'unknown',
            'effective_date' => $data['effectiveDate'] ?? $effectiveDate ?? now()->toDateString(),
            'total_items' => count($tariffItems),
            'items' => array_map(fn($item) => [
                'code' => $item['code'] ?? $item['tariffCode'] ?? null,
                'name' => $item['name'] ?? $item['serviceName'] ?? null,
                'price' => $item['price'] ?? $item['amount'] ?? $item['tariff'] ?? 0,
                'category' => $item['category'] ?? null,
            ], $tariffItems),
        ];
    }
}
