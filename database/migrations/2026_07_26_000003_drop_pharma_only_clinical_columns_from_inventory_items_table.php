<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory_MasterData_Alignment_Plan.md Phase 3 (contract step of the
 * expand -> migrate -> contract sequence started by 2026_07_26_000001/2).
 *
 * Drops only the five columns that are safe to drop: generic_name,
 * dosage_form, strength, is_controlled_substance, controlled_substance_schedule.
 * Every one of these is gated to InventoryItemCategory::PHARMACEUTICAL (see
 * supportsMedicineDetails() / isControlledSubstanceEligible()), and
 * Pharmaceutical items are required to carry a clinical_catalog_item_id
 * (InventoryClinicalLinkGuard), so every row that could ever have populated
 * these columns has a Clinical Catalog row to read them from instead
 * (platform_clinical_catalog_items, columns added in 2026_07_26_000001).
 *
 * storage_conditions and requires_cold_chain are deliberately NOT dropped
 * here -- supportsStorageFields() also covers BLOOD_PRODUCT, LABORATORY, and
 * FOOD_NUTRITION, none of which can hold a clinical_catalog_item_id, so
 * Inventory is the only possible owner of those two columns for three of the
 * four categories that use them. Dropping them would have deleted the only
 * place those three categories can record cold-chain/storage requirements.
 */
return new class extends Migration
{
    public function up(): void
    {
        // is_controlled_substance carries an index (2026_04_19_000700). SQLite's
        // "recreate table" strategy for dropColumn() can't drop an indexed column and
        // its index in the same pass the way Postgres can -- drop the index first,
        // explicitly, so this migration behaves the same on both drivers. Existence
        // check first since this runs against both a fresh test schema and a dev
        // database that may already be missing the index for unrelated reasons.
        $hasIndex = collect(Schema::getIndexes('inventory_items'))
            ->contains(fn (array $index) => $index['name'] === 'inventory_items_is_controlled_substance_index');
        if ($hasIndex) {
            Schema::table('inventory_items', function (Blueprint $table): void {
                $table->dropIndex(['is_controlled_substance']);
            });
        }

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn([
                'generic_name',
                'dosage_form',
                'strength',
                'is_controlled_substance',
                'controlled_substance_schedule',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->string('generic_name', 180)->nullable()->after('item_name');
            $table->string('dosage_form', 60)->nullable()->after('generic_name');
            $table->string('strength', 60)->nullable()->after('dosage_form');
            $table->boolean('is_controlled_substance')->default(false)->after('requires_cold_chain');
            $table->string('controlled_substance_schedule', 20)->nullable()->after('is_controlled_substance');
            $table->index(['is_controlled_substance']);
        });
    }
};
