<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PricingEngine_Migration_Plan.md Phase 3, Radiology prerequisite. The
 * earlier heuristic backfill (2026_04_21_000100) only linked
 * billing_service_catalog_items to platform_clinical_catalog_items via a
 * clinical_catalog_item.metadata.billingServiceCode match. Every radiology
 * tariff in this dataset never had that metadata key set, so all 15 stayed
 * unlinked -- confirmed by Phase 1's backfill dry-run and again by a live
 * shadow-diff simulation on 2026-07-24 (RAD-ABD-001 came back
 * missing_price_book_entry despite a real price existing).
 *
 * A simpler, exact match works instead: every one of these 15 rows'
 * service_code equals a platform_clinical_catalog_items.code for a
 * catalog_type='radiology_procedure' row in the *same* tenant/facility
 * scope, one-to-one, no ambiguity (verified against the live dataset
 * before writing this migration). Link by that instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        $unlinkedTariffs = DB::table('billing_service_catalog_items')
            ->whereNull('clinical_catalog_item_id')
            ->where('service_type', 'radiology')
            ->get(['id', 'service_code', 'tenant_id', 'facility_id']);

        foreach ($unlinkedTariffs as $tariff) {
            $catalogItem = $this->findMatchingCatalogItem($tariff);

            if ($catalogItem === null) {
                continue;
            }

            DB::table('billing_service_catalog_items')
                ->where('id', $tariff->id)
                ->update([
                    'clinical_catalog_item_id' => $catalogItem->id,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: reversing would re-orphan real pricing
        // data this migration correctly linked, and there is no reliable
        // way to distinguish rows it linked from rows already linked
        // before it ran.
    }

    private function findMatchingCatalogItem(object $tariff): ?object
    {
        return DB::table('platform_clinical_catalog_items')
            ->where('catalog_type', 'radiology_procedure')
            ->where('code', $tariff->service_code)
            ->when(
                $tariff->tenant_id === null,
                fn ($query) => $query->whereNull('tenant_id'),
                fn ($query) => $query->where('tenant_id', $tariff->tenant_id),
            )
            ->when(
                $tariff->facility_id === null,
                fn ($query) => $query->whereNull('facility_id'),
                fn ($query) => $query->where('facility_id', $tariff->facility_id),
            )
            ->first();
    }
};
