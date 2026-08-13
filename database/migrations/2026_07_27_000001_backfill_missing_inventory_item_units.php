<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill inventory_item_units for items seeded after the original Phase 4 migration.
 * The original migration (2026_07_26_000005) ran before DskInventoryItemsSeeder was
 * executed, so 250+ items created by that seeder lack base unit rows and cause
 * "Selected inventory unit could not be resolved" errors during recipe consumption.
 */
return new class extends Migration
{
    public function up(): void
    {
        $itemsMissingBaseUnit = DB::table('inventory_items as i')
            ->select(['i.id', 'i.tenant_id', 'i.facility_id', 'i.unit', 'i.dispensing_unit', 'i.conversion_factor'])
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('inventory_item_units as u')
                    ->whereColumn('u.item_id', 'i.id')
                    ->where('u.is_base_unit', true)
                    ->where('u.is_active', true);
            })
            ->get();

        $now = now();
        $seeded = 0;

        foreach ($itemsMissingBaseUnit->chunk(200) as $chunk) {
            $rows = [];

            foreach ($chunk as $item) {
                $unitName = trim((string) ($item->unit ?? ''));
                if ($unitName === '') {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $item->tenant_id,
                    'facility_id' => $item->facility_id,
                    'item_id' => $item->id,
                    'unit_name' => $unitName,
                    'unit_code' => $unitName,
                    'base_quantity' => 1.0,
                    'is_base_unit' => true,
                    'is_default_sales_unit' => true,
                    'is_default_purchase_unit' => true,
                    'is_active' => true,
                    'barcode' => null,
                    'metadata' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $dispensingUnit = strtolower(trim((string) ($item->dispensing_unit ?? '')));
                $conversionFactor = (float) ($item->conversion_factor ?? 0);
                if ($dispensingUnit !== '' && $dispensingUnit !== strtolower($unitName) && $conversionFactor > 0) {
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $item->tenant_id,
                        'facility_id' => $item->facility_id,
                        'item_id' => $item->id,
                        'unit_name' => $dispensingUnit,
                        'unit_code' => $dispensingUnit,
                        'base_quantity' => round(1.0 / $conversionFactor, 6),
                        'is_base_unit' => false,
                        'is_default_sales_unit' => false,
                        'is_default_purchase_unit' => false,
                        'is_active' => true,
                        'barcode' => null,
                        'metadata' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows !== []) {
                DB::table('inventory_item_units')->insert($rows);
                $seeded += count($rows);
            }
        }

        \Illuminate\Support\Facades\Log::info('Backfill missing inventory_item_units complete.', [
            'itemsConsidered' => $itemsMissingBaseUnit->count(),
            'unitRowsSeeded' => $seeded,
        ]);
    }

    public function down(): void
    {
        // No-op: cannot safely distinguish rows this migration created from user edits
    }
};