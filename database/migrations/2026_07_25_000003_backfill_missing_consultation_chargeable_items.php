<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PricingEngine_Migration_Plan.md Phase 5: the legacy consultation catalog
 * (billing_service_catalog_items, service_type=consultation) had 17 rows;
 * only 2 were ever copied into chargeable_items (the ones already used by
 * existing consultation_mappings). The other 15 have no equivalent in the
 * new engine at all -- discovered via two real symptoms: (1) the
 * consultation-mapping admin's "Pricing item" picker only showing 2
 * options instead of 17, and (2) a real regression risk in payer-contract
 * price overrides / manual invoice auto-pricing, both of which now resolve
 * via chargeable_items and would hard-fail for any of these 15 service
 * codes where they used to succeed against the old table.
 *
 * One-time data backfill, not a schema change -- copies the 15 missing
 * rows' code/name/price into chargeable_items + price_book_entries.
 * Idempotent (skips any code that already exists) so it's safe to run
 * more than once.
 *
 * Rewritten 2026-08-18 (Cashier Phase 2) to use the query builder instead of
 * BillingServiceCatalogItemModel / PriceBookEntryModel / ChargeableItemModel.
 * Two of those classes were deleted with the Billing module and the third
 * moved, which would have made this historical migration fatal on any fresh
 * `migrate` — including every test run. Behaviour is unchanged; only the
 * access path is. The source table is also dropped later in the same phase,
 * so the guard below makes the migration a no-op once that happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        $missingCodes = [
            'CONSULTATION', 'CONSULT-OUTPATIENT', 'CONSULT-GENERAL-OPD',
            'CONSULT-CO-OUTPATIENT', 'CONSULT-AMO-OUTPATIENT',
            'CONSULT-MD-OUTPATIENT', 'CONSULT-MD-GENERAL-OPD',
            'CONSULT-SPECIALIST-OUTPATIENT', 'CONSULT-SPECIALIST-GENERAL-OPD',
            'CONSULT-SPECIALIST-CARDIOLOGY', 'CONSULT-SPECIALIST-GENERAL-SURGERY',
            'CONSULT-SPECIALIST-TZ-GEN-SURG', 'CONSULT-SPECIALIST-ANAESTHESIA',
            'CONSULT-SPECIALIST-TZ-ANESTH', 'DC',
        ];

        if (! Schema::hasTable('billing_service_catalog_items')) {
            return;
        }

        $legacyRows = DB::table('billing_service_catalog_items')
            ->whereIn('service_code', $missingCodes)
            ->get();

        foreach ($legacyRows as $legacyRow) {
            $code = strtoupper(trim((string) $legacyRow->service_code));

            $alreadyExists = DB::table('chargeable_items')
                ->whereRaw('UPPER(code) = ?', [$code])
                ->exists();
            if ($alreadyExists) {
                continue;
            }

            $chargeableItemId = (string) Str::uuid();

            DB::table('chargeable_items')->insert([
                'id' => $chargeableItemId,
                'catalog_type' => 'consultation',
                'charge_model' => 'flat',
                'code' => $code,
                'name' => $legacyRow->service_name,
                'default_unit' => $legacyRow->unit,
                'status' => 'active',
                'is_taxable' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('price_book_entries')->insert([
                'id' => (string) Str::uuid(),
                'chargeable_item_id' => $chargeableItemId,
                'currency_code' => strtoupper((string) $legacyRow->currency_code),
                'unit_price' => $legacyRow->base_price,
                'tax_rate_percent' => 0,
                'is_taxable' => false,
                'tariff_version' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally no-op: this is a one-time data backfill. Rolling
        // back would delete real chargeable_items/price_book_entries rows
        // that may already be in live use by the time anyone rolls back.
    }
};
