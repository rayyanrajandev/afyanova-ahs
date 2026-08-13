<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PricingEngine_Migration_Plan.md Phase 5 legacy removal, Consultation
 * (first domain fully cut over). chargeable_item_id is now the sole
 * pricing path for consultation mappings -- billing_service_catalog_item_id
 * is kept (not dropped; PricingEngine_Removal_Inventory.md keeps the whole
 * billing_service_catalog_items table until every domain is done) but is no
 * longer required, since new mappings created going forward have no reason
 * to reference the legacy table at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_mappings', function (Blueprint $table): void {
            $table->dropForeign(['billing_service_catalog_item_id']);
        });

        Schema::table('consultation_mappings', function (Blueprint $table): void {
            $table->foreignUuid('billing_service_catalog_item_id')
                ->nullable()
                ->change();

            $table->foreign('billing_service_catalog_item_id')
                ->references('id')
                ->on('billing_service_catalog_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consultation_mappings', function (Blueprint $table): void {
            $table->dropForeign(['billing_service_catalog_item_id']);
        });

        Schema::table('consultation_mappings', function (Blueprint $table): void {
            $table->foreignUuid('billing_service_catalog_item_id')
                ->nullable(false)
                ->change();

            $table->foreign('billing_service_catalog_item_id')
                ->references('id')
                ->on('billing_service_catalog_items')
                ->cascadeOnDelete();
        });
    }
};
