<?php

namespace App\Console\Commands;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitPriceModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryBackfillItemUnits extends Command
{
    protected $signature = 'inventory:backfill-item-units {--dry-run : Preview changes without writing to the database}';
    protected $description = 'Backfill inventory_item_units and inventory_item_unit_prices from legacy inventory_items data';

    public function handle(): void
    {
        $isDryRun = $this->option('dry-run');

        $this->info('Starting inventory item units backfill...');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE — no data will be written.');
        }

        $stats = [
            'items_scanned' => 0,
            'base_units_created' => 0,
            'selling_units_created' => 0,
            'price_rows_created' => 0,
            'items_skipped' => 0,
            'ambiguous_matches' => 0,
        ];

        DB::transaction(function (): void {
            //
        });

        $query = InventoryItemModel::query()
            ->whereDoesntHave('units')
            ->orderBy('id');

        $items = $query->get();

        foreach ($items as $item) {
            $stats['items_scanned']++;

            $legacyUnit = strtolower(trim((string) ($item->unit ?? '')));
            if ($legacyUnit === '') {
                $stats['items_skipped']++;

                continue;
            }

            $itemId = (string) $item->id;
            $tenantId = $item->tenant_id !== null ? (string) $item->tenant_id : null;
            $facilityId = $item->facility_id !== null ? (string) $item->facility_id : null;

            if (! $isDryRun) {
                InventoryItemUnitModel::query()->create([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'item_id' => $item->id,
                    'unit_name' => $legacyUnit,
                    'unit_code' => null,
                    'base_quantity' => 1.0,
                    'is_base_unit' => true,
                    'is_default_sales_unit' => true,
                    'is_default_purchase_unit' => true,
                    'is_active' => true,
                    'barcode' => $item->barcode ?? null,
                    'metadata' => ['backfilled_from_legacy_unit' => true],
                ]);
            }

            $stats['base_units_created']++;

            $dispensingUnit = strtolower(trim((string) ($item->dispensing_unit ?? '')));
            $conversionFactor = (float) ($item->conversion_factor ?? 0);

            if ($dispensingUnit !== '' && $dispensingUnit !== $legacyUnit && $conversionFactor > 0) {
                if (! $isDryRun) {
                    InventoryItemUnitModel::query()->create([
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'tenant_id' => $tenantId,
                        'facility_id' => $facilityId,
                        'item_id' => $item->id,
                        'unit_name' => $dispensingUnit,
                        'unit_code' => null,
                        'base_quantity' => round(1.0 / $conversionFactor, 6),
                        'is_base_unit' => false,
                        'is_default_sales_unit' => false,
                        'is_default_purchase_unit' => false,
                        'is_active' => true,
                        'barcode' => null,
                        'metadata' => ['backfilled_from_legacy_dispensing_unit' => true],
                    ]);
                }

                $stats['selling_units_created']++;
            }

            $currentStock = (float) ($item->current_stock ?? 0);
            if ($currentStock > 0 && ! $isDryRun) {
                $baseUnit = InventoryItemUnitModel::query()
                    ->where('item_id', $item->id)
                    ->where('is_base_unit', true)
                    ->first();

                if ($baseUnit instanceof InventoryItemUnitModel) {
                    InventoryItemUnitPriceModel::query()->create([
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'tenant_id' => $tenantId,
                        'facility_id' => $facilityId,
                        'item_id' => $item->id,
                        'inventory_item_unit_id' => $baseUnit->id,
                        'price_type' => 'retail',
                        'billing_payer_contract_id' => null,
                        'price' => 0.0,
                        'currency_code' => 'TZS',
                        'effective_from' => now(),
                        'effective_to' => null,
                        'is_active' => true,
                        'metadata' => ['backfilled_default_retail_price' => true],
                    ]);

                    $stats['price_rows_created']++;
                }
            }
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Items scanned', $stats['items_scanned']],
                ['Base units created', $stats['base_units_created']],
                ['Selling units created', $stats['selling_units_created']],
                ['Price rows created', $stats['price_rows_created']],
                ['Items skipped', $stats['items_skipped']],
                ['Ambiguous matches', $stats['ambiguous_matches']],
            ]
        );

        $this->info('Backfill complete.');
    }
}