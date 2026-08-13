<?php

namespace App\Console\Commands;

use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Support\CatalogGovernance\ChargeableItemCatalogSync;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * PricingEngine_Migration_Plan.md Phase 1. Additive-only backfill: creates
 * `chargeable_items` rows (reusing the source row's own id, since a
 * chargeable item IS the same real-world thing as its clinical catalog
 * counterpart -- no separate lookup table needed for traceability) and
 * `price_book_entries` rows linked by that same id. Nothing reads these new
 * tables yet (that's Phase 2/3) -- this command only populates them.
 *
 * Idempotent: safe to re-run. Chargeable items upsert by id. Price book
 * entries skip if a row already exists for the same
 * (chargeable_item_id, tenant_id, facility_id, payer_contract_id, effective_from)
 * tuple -- the same uniqueness PricingEngine_Technical_Design.md documents
 * as enforced at the application layer.
 *
 * billing_service_catalog_items rows with no clinical_catalog_item_id
 * (bed-day/consultation rows, priced by the legacy string-code convention)
 * are intentionally left unlinked and reported, not migrated here -- they
 * need their domain-specific Phase 3 migration first.
 */
class PricingBackfillChargeableItems extends Command
{
    protected $signature = 'pricing:backfill-chargeable-items {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Phase 1: backfill chargeable_items and price_book_entries from platform_clinical_catalog_items and billing_service_catalog_items';

    public function handle(ChargeableItemCatalogSync $chargeableItemCatalogSync): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->info('Starting pricing engine Phase 1 backfill...');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE — no data will be written.');
        }

        $stats = [
            'catalog_items_scanned' => 0,
            'chargeable_items_created' => 0,
            'chargeable_items_updated' => 0,
            'price_rows_scanned' => 0,
            'price_book_entries_created' => 0,
            'price_book_entries_skipped_existing' => 0,
            'price_book_entries_unlinked' => 0,
            'supersedes_links_rebuilt' => 0,
        ];

        $this->backfillChargeableItems($isDryRun, $stats, $chargeableItemCatalogSync);
        $legacyToNewPriceId = $this->backfillPriceBookEntries($isDryRun, $stats);
        $this->rebuildSupersedesLinks($isDryRun, $stats, $legacyToNewPriceId);
        $unlinkedReport = $this->reportUnlinkedPriceRows();

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Clinical catalog items scanned', $stats['catalog_items_scanned']],
                ['Chargeable items created', $stats['chargeable_items_created']],
                ['Chargeable items updated (already existed)', $stats['chargeable_items_updated']],
                ['Billing service catalog rows scanned', $stats['price_rows_scanned']],
                ['Price book entries created', $stats['price_book_entries_created']],
                ['Price book entries skipped (already backfilled)', $stats['price_book_entries_skipped_existing']],
                ['Rows left unlinked (no clinical_catalog_item_id)', $stats['price_book_entries_unlinked']],
                ['Supersedes-chain links rebuilt', $stats['supersedes_links_rebuilt']],
            ],
        );

        if ($unlinkedReport !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '%d billing_service_catalog_items row(s) have no clinical_catalog_item_id and were NOT backfilled. '.
                'These need their domain-specific Phase 3 migration (bed-day / consultation) before they can link '.
                'to a chargeable item. Review with the Billing owner before proceeding, per the Migration Plan '.
                'Phase 1 verification gate.',
                count($unlinkedReport),
            ));
            $this->table(['service_code', 'service_type', 'status'], $unlinkedReport);
        }

        $this->info($isDryRun ? 'Dry run complete — no data was written.' : 'Backfill complete.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function backfillChargeableItems(bool $isDryRun, array &$stats, ChargeableItemCatalogSync $chargeableItemCatalogSync): void
    {
        $catalogItems = ClinicalCatalogItemModel::query()->get();

        foreach ($catalogItems as $catalogItem) {
            $stats['catalog_items_scanned']++;

            $existing = ChargeableItemModel::query()->find($catalogItem->id);

            if ($existing !== null) {
                $stats['chargeable_items_updated']++;
                if (! $isDryRun) {
                    $chargeableItemCatalogSync->sync($catalogItem);
                }

                continue;
            }

            $stats['chargeable_items_created']++;
            if ($isDryRun) {
                continue;
            }

            // Everyday creation goes through ChargeableItemCatalogSync's
            // create-now path (matches EloquentClinicalCatalogItemRepository),
            // but backfilling *pre-existing* catalog items should preserve
            // their original created_at/updated_at rather than stamping
            // "now" -- this is historical data, not a fresh row.
            $chargeableItemCatalogSync->sync($catalogItem);
            ChargeableItemModel::query()
                ->where('id', $catalogItem->id)
                ->update([
                    'created_at' => $catalogItem->created_at,
                    'updated_at' => $catalogItem->updated_at,
                ]);
        }
    }

    /**
     * @param  array<string, int>  $stats
     * @return array<string, string> legacy billing_service_catalog_items.id => new price_book_entries.id
     */
    private function backfillPriceBookEntries(bool $isDryRun, array &$stats): array
    {
        $legacyToNewId = [];

        $priceRows = BillingServiceCatalogItemModel::query()
            ->whereNotNull('clinical_catalog_item_id')
            ->orderBy('created_at')
            ->get();

        foreach ($priceRows as $priceRow) {
            $stats['price_rows_scanned']++;

            $alreadyBackfilled = PriceBookEntryModel::query()
                ->where('chargeable_item_id', $priceRow->clinical_catalog_item_id)
                ->where(fn ($q) => $priceRow->tenant_id === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $priceRow->tenant_id))
                ->where(fn ($q) => $priceRow->facility_id === null ? $q->whereNull('facility_id') : $q->where('facility_id', $priceRow->facility_id))
                ->whereNull('payer_contract_id')
                ->where(fn ($q) => $priceRow->effective_from === null ? $q->whereNull('effective_from') : $q->where('effective_from', $priceRow->effective_from))
                ->first();

            if ($alreadyBackfilled !== null) {
                $stats['price_book_entries_skipped_existing']++;
                $legacyToNewId[$priceRow->id] = $alreadyBackfilled->id;

                continue;
            }

            $stats['price_book_entries_created']++;
            if ($isDryRun) {
                continue;
            }

            $newId = (string) Str::orderedUuid();
            $priceBookEntry = new PriceBookEntryModel();
            $priceBookEntry->id = $newId;
            $priceBookEntry->fill([
                'chargeable_item_id' => $priceRow->clinical_catalog_item_id,
                'tenant_id' => $priceRow->tenant_id,
                'facility_id' => $priceRow->facility_id,
                'facility_tier' => $priceRow->facility_tier,
                'currency_code' => $priceRow->currency_code,
                'unit_price' => $priceRow->base_price,
                'tax_rate_percent' => $priceRow->tax_rate_percent,
                'is_taxable' => $priceRow->is_taxable,
                'effective_from' => $priceRow->effective_from,
                'effective_to' => $priceRow->effective_to,
                'tariff_version' => $priceRow->tariff_version,
                'status' => $priceRow->status,
                'status_reason' => $priceRow->status_reason,
            ]);
            $priceBookEntry->created_at = $priceRow->created_at;
            $priceBookEntry->updated_at = $priceRow->updated_at;
            $priceBookEntry->save();

            $legacyToNewId[$priceRow->id] = $newId;
        }

        return $legacyToNewId;
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<string, string>  $legacyToNewId
     */
    private function rebuildSupersedesLinks(bool $isDryRun, array &$stats, array $legacyToNewId): void
    {
        if ($isDryRun || $legacyToNewId === []) {
            return;
        }

        $priceRows = BillingServiceCatalogItemModel::query()
            ->whereNotNull('clinical_catalog_item_id')
            ->whereNotNull('supersedes_billing_service_catalog_item_id')
            ->get(['id', 'supersedes_billing_service_catalog_item_id']);

        foreach ($priceRows as $priceRow) {
            $newId = $legacyToNewId[$priceRow->id] ?? null;
            $newSupersedesId = $legacyToNewId[$priceRow->supersedes_billing_service_catalog_item_id] ?? null;

            if ($newId === null || $newSupersedesId === null) {
                continue;
            }

            $updated = PriceBookEntryModel::query()
                ->where('id', $newId)
                ->whereNull('supersedes_price_book_entry_id')
                ->update(['supersedes_price_book_entry_id' => $newSupersedesId]);

            if ($updated > 0) {
                $stats['supersedes_links_rebuilt']++;
            }
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function reportUnlinkedPriceRows(): array
    {
        return BillingServiceCatalogItemModel::query()
            ->whereNull('clinical_catalog_item_id')
            ->orderBy('service_code')
            ->get(['service_code', 'service_type', 'status'])
            ->map(fn (BillingServiceCatalogItemModel $row): array => [
                (string) $row->service_code,
                (string) ($row->service_type ?? ''),
                (string) $row->status,
            ])
            ->all();
    }
}
