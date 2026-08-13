<?php

namespace App\Console\Commands;

use App\Modules\Billing\Infrastructure\Models\ConsultationMappingModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use Illuminate\Console\Command;

/**
 * PricingEngine_Migration_Plan.md Phase 3, Consultation. Consultation has no
 * platform_clinical_catalog_items row of any kind to backfill from (the
 * ClinicalCatalogType enum has no consultation case) -- unlike every other
 * domain's Phase 1 backfill, this one creates brand-new chargeable_items
 * rows (fresh UUIDs, no id-reuse) rather than mirroring an existing catalog
 * item id. Only migrates consultation_mappings rows that actually exist --
 * the other consultation tariffs with no explicit mapping intentionally
 * stay on the string-match fallback (see ConsultationPricingResolver).
 *
 * Idempotent: re-running reuses an existing chargeable_items row for the
 * same (catalog_type=consultation, code, tenant, facility) instead of
 * creating a duplicate, and skips price_book_entries creation if a
 * matching row already exists.
 */
class PricingBackfillConsultationChargeableItems extends Command
{
    protected $signature = 'pricing:backfill-consultation-chargeable-items {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Phase 3 (Consultation): backfill chargeable_items/price_book_entries for consultation_mappings rows and link them';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->info('Starting consultation mapping chargeable-item backfill...');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE — no data will be written.');
        }

        $stats = [
            'mappings_scanned' => 0,
            'mappings_linked' => 0,
            'mappings_skipped_no_tariff' => 0,
            'chargeable_items_created' => 0,
            'chargeable_items_reused' => 0,
            'price_book_entries_created' => 0,
            'price_book_entries_skipped_existing' => 0,
        ];

        $mappings = ConsultationMappingModel::query()
            ->whereNull('chargeable_item_id')
            ->with('billingServiceCatalogItem')
            ->get();

        foreach ($mappings as $mapping) {
            $stats['mappings_scanned']++;

            $tariff = $mapping->billingServiceCatalogItem;
            if ($tariff === null) {
                $stats['mappings_skipped_no_tariff']++;
                $this->warn(sprintf(
                    'Mapping %s/%s references billing_service_catalog_item_id=%s which no longer exists — skipped.',
                    $mapping->clinician_tier,
                    $mapping->department,
                    $mapping->billing_service_catalog_item_id,
                ));

                continue;
            }

            $chargeableItem = ChargeableItemModel::query()
                ->where('catalog_type', 'consultation')
                ->where('code', $tariff->service_code)
                ->where(fn ($q) => $tariff->tenant_id === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tariff->tenant_id))
                ->where(fn ($q) => $tariff->facility_id === null ? $q->whereNull('facility_id') : $q->where('facility_id', $tariff->facility_id))
                ->first();

            if ($chargeableItem !== null) {
                $stats['chargeable_items_reused']++;
            } else {
                $stats['chargeable_items_created']++;
                if (! $isDryRun) {
                    $chargeableItem = new ChargeableItemModel();
                    $chargeableItem->fill([
                        'tenant_id' => $tariff->tenant_id,
                        'facility_id' => $tariff->facility_id,
                        'facility_tier' => $tariff->facility_tier,
                        'catalog_type' => 'consultation',
                        'charge_model' => 'flat',
                        'code' => $tariff->service_code,
                        'name' => $tariff->service_name,
                        'default_unit' => $tariff->unit,
                        'status' => 'active',
                    ]);
                    $chargeableItem->save();
                }
            }

            if ($isDryRun) {
                $stats['mappings_linked']++;

                continue;
            }

            $existingPriceBookEntry = PriceBookEntryModel::query()
                ->where('chargeable_item_id', $chargeableItem->id)
                ->where(fn ($q) => $tariff->tenant_id === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tariff->tenant_id))
                ->where(fn ($q) => $tariff->facility_id === null ? $q->whereNull('facility_id') : $q->where('facility_id', $tariff->facility_id))
                ->whereNull('payer_contract_id')
                ->first();

            if ($existingPriceBookEntry !== null) {
                $stats['price_book_entries_skipped_existing']++;
            } else {
                $stats['price_book_entries_created']++;
                PriceBookEntryModel::query()->create([
                    'chargeable_item_id' => $chargeableItem->id,
                    'tenant_id' => $tariff->tenant_id,
                    'facility_id' => $tariff->facility_id,
                    'facility_tier' => $tariff->facility_tier,
                    'currency_code' => $tariff->currency_code,
                    'unit_price' => $tariff->base_price,
                    'tax_rate_percent' => $tariff->tax_rate_percent,
                    'is_taxable' => $tariff->is_taxable,
                    'effective_from' => $tariff->effective_from,
                    'effective_to' => $tariff->effective_to,
                    'tariff_version' => $tariff->tariff_version,
                    'status' => $tariff->status,
                    'status_reason' => $tariff->status_reason,
                ]);
            }

            $mapping->chargeable_item_id = $chargeableItem->id;
            $mapping->save();
            $stats['mappings_linked']++;
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Consultation mappings scanned', $stats['mappings_scanned']],
                ['Mappings linked to a chargeable item', $stats['mappings_linked']],
                ['Mappings skipped (tariff no longer exists)', $stats['mappings_skipped_no_tariff']],
                ['Chargeable items created', $stats['chargeable_items_created']],
                ['Chargeable items reused (already existed)', $stats['chargeable_items_reused']],
                ['Price book entries created', $stats['price_book_entries_created']],
                ['Price book entries skipped (already existed)', $stats['price_book_entries_skipped_existing']],
            ],
        );

        $this->info($isDryRun ? 'Dry run complete — no data was written.' : 'Backfill complete.');

        return self::SUCCESS;
    }
}
