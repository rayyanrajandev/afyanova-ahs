<?php

namespace Tests\Feature\Revenue;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Minimal fixtures for the revenue ledger.
 *
 * Deliberately raw inserts rather than factories: chargeable_items and
 * price_book_entries have no factories, and the tests below are about exact
 * amounts, so building the tariff explicitly keeps each test's arithmetic
 * visible in the test itself.
 */
final class RevenueTestSupport
{
    /**
     * @return array{chargeableItemId: string, priceBookEntryId: string}
     */
    public static function pricedItem(
        string $code,
        string $unitPrice,
        string $currency = 'TZS',
        bool $taxable = false,
        ?float $taxRatePercent = null,
        ?string $tenantId = null,
        ?string $facilityId = null,
        string $chargeModel = 'flat',
    ): array {
        $itemId = (string) Str::uuid();
        $entryId = (string) Str::uuid();

        DB::table('chargeable_items')->insert([
            'id' => $itemId,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'catalog_type' => 'consultation',
            'charge_model' => $chargeModel,
            'code' => $code,
            'name' => 'Test item '.$code,
            'default_unit' => 'visit',
            'status' => 'active',
            'is_taxable' => $taxable,
            'tax_rate_percent' => $taxRatePercent,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('price_book_entries')->insert([
            'id' => $entryId,
            'chargeable_item_id' => $itemId,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'payer_contract_id' => null,
            'currency_code' => $currency,
            'unit_price' => $unitPrice,
            'tax_rate_percent' => $taxRatePercent ?? 0,
            'is_taxable' => $taxable,
            'tariff_version' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['chargeableItemId' => $itemId, 'priceBookEntryId' => $entryId];
    }

    public static function unpricedItem(string $code): string
    {
        $itemId = (string) Str::uuid();

        DB::table('chargeable_items')->insert([
            'id' => $itemId,
            'catalog_type' => 'consultation',
            'charge_model' => 'flat',
            'code' => $code,
            'name' => 'Unpriced '.$code,
            'default_unit' => 'visit',
            'status' => 'active',
            'is_taxable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $itemId;
    }

    public static function patientId(): string
    {
        return (string) Str::uuid();
    }
}
