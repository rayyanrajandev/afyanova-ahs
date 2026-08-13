<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_items', 'clinical_catalog_item_id')) {
                $table->uuid('clinical_catalog_item_id')->nullable()->after('facility_id');
                $table->index('clinical_catalog_item_id', 'inventory_items_clinical_catalog_item_id_idx');
                $table->foreign('clinical_catalog_item_id', 'inventory_items_clinical_catalog_item_id_fk')
                    ->references('id')
                    ->on('platform_clinical_catalog_items')
                    ->nullOnDelete();
            }
        });

        $inventoryRows = DB::table('inventory_items')
            ->select(['id', 'tenant_id', 'facility_id', 'item_code', 'item_name', 'category', 'clinical_catalog_item_id'])
            ->where('category', 'pharmaceutical')
            ->get();

        foreach ($inventoryRows as $inventoryRow) {
            if ($inventoryRow->clinical_catalog_item_id !== null) {
                continue;
            }

            $matchedClinicalCatalogItemId = $this->findFormularyCatalogItemId(
                itemCode: (string) ($inventoryRow->item_code ?? ''),
                itemName: (string) ($inventoryRow->item_name ?? ''),
                tenantId: $inventoryRow->tenant_id,
                facilityId: $inventoryRow->facility_id,
            );

            if ($matchedClinicalCatalogItemId === null) {
                continue;
            }

            DB::table('inventory_items')
                ->where('id', $inventoryRow->id)
                ->update([
                    'clinical_catalog_item_id' => $matchedClinicalCatalogItemId,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_items', 'clinical_catalog_item_id')) {
                $table->dropForeign('inventory_items_clinical_catalog_item_id_fk');
                $table->dropIndex('inventory_items_clinical_catalog_item_id_idx');
                $table->dropColumn('clinical_catalog_item_id');
            }
        });
    }

    private function findFormularyCatalogItemId(
        string $itemCode,
        string $itemName,
        ?string $tenantId,
        ?string $facilityId,
    ): ?string {
        $normalizedCode = strtoupper(trim($itemCode));
        $normalizedName = mb_strtolower(trim($itemName));

        if ($normalizedCode === '' && $normalizedName === '') {
            return null;
        }

        $query = DB::table('platform_clinical_catalog_items')
            ->select(['id'])
            ->when(
                $tenantId === null,
                fn ($query) => $query->whereNull('tenant_id'),
                fn ($query) => $query->where('tenant_id', $tenantId),
            )
            ->when(
                $facilityId === null,
                fn ($query) => $query->whereNull('facility_id'),
                fn ($query) => $query->where('facility_id', $facilityId),
            )
            ->where('catalog_type', 'formulary_item')
            ->where('status', 'active')
            ->where(function ($query) use ($normalizedCode, $normalizedName): void {
                if ($normalizedCode !== '') {
                    $query->orWhereRaw('UPPER(code) = ?', [$normalizedCode]);
                }

                if ($normalizedName !== '') {
                    $query->orWhereRaw('LOWER(name) = ?', [$normalizedName]);
                }
            });

        if ($normalizedCode !== '') {
            $query->orderByRaw('CASE WHEN UPPER(code) = ? THEN 0 ELSE 1 END', [$normalizedCode]);
        }

        $match = $query->first();

        return $match ? (string) $match->id : null;
    }
};
