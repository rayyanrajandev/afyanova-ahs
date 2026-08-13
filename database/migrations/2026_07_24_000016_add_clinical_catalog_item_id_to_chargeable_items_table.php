<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes a real gap found while auditing catalog sync: chargeable_items had
 * no foreign key to platform_clinical_catalog_items at all, only an ID-reuse
 * *convention* (Phase 1's backfill sets chargeable_items.id equal to the
 * source clinical_catalog_item's id for the 5 order-domains). That meant no
 * real link existed for a query, a relation, or a read-time override to hang
 * off of -- unlike billing_service_catalog_items and inventory_items, which
 * both already have a proper clinical_catalog_item_id FK and read identity
 * fields live from the catalog. This makes chargeable_items follow the same
 * established pattern instead of being a regression from it.
 *
 * Consultation and bed_day chargeable items have no clinical catalog
 * counterpart at all (by design -- they're not clinical definitions), so
 * clinical_catalog_item_id stays null for those; their code/name/category/
 * default_unit remain the authoritative, directly-entered values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chargeable_items', function (Blueprint $table): void {
            $table->uuid('clinical_catalog_item_id')->nullable()->after('id');

            $table->foreign('clinical_catalog_item_id')
                ->references('id')
                ->on('platform_clinical_catalog_items')
                ->nullOnDelete();

            $table->index('clinical_catalog_item_id', 'chargeable_items_clinical_catalog_item_id_idx');
        });

        // Backfill: for every chargeable_items row whose id equals a real
        // platform_clinical_catalog_items id (the ID-reuse convention),
        // set the new column so it's a real, queryable link from here on.
        // Query-builder form (not raw UPDATE...FROM) so this runs the same
        // way against both the live pgsql database and sqlite in tests.
        $catalogIds = DB::table('platform_clinical_catalog_items')->pluck('id');

        foreach ($catalogIds->chunk(500) as $chunk) {
            DB::table('chargeable_items')
                ->whereIn('id', $chunk)
                ->update(['clinical_catalog_item_id' => DB::raw('id')]);
        }
    }

    public function down(): void
    {
        Schema::table('chargeable_items', function (Blueprint $table): void {
            $table->dropForeign(['clinical_catalog_item_id']);
            $table->dropIndex('chargeable_items_clinical_catalog_item_id_idx');
            $table->dropColumn('clinical_catalog_item_id');
        });
    }
};
