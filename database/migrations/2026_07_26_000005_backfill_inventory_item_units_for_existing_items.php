<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inventory_MasterData_Alignment_Plan.md Phase 4 backfill. CreateInventoryItemUseCase
 * has auto-seeded inventory_item_units on every new item for a while, but items
 * created before that logic existed (or via a path that bypassed it, e.g. direct
 * bulk-sync in earlier states of this codebase) never got a units row at all --
 * meaning InventoryUnitConversionService's resolveBaseUnit() has nothing to find
 * for them. Gives every item with no active base unit one, mirroring
 * CreateInventoryItemUseCase's own seeding logic exactly so behavior is consistent
 * regardless of when the item was created.
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

        \Illuminate\Support\Facades\Log::info('Phase 4 inventory_item_units backfill complete.', [
            'itemsConsidered' => $itemsMissingBaseUnit->count(),
            'unitRowsSeeded' => $seeded,
        ]);
    }

    public function down(): void
    {
        // Intentionally a no-op: reversing would discard real packaging data this
        // migration backfilled, and there is no way to distinguish rows it created
        // from rows a facility has since edited.
    }
};
