<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PricingEngine_Migration_Plan.md Phase 5: payer price overrides now
 * resolve their linked service via chargeable_items, not
 * billing_service_catalog_items -- billing_service_catalog_item_id is a
 * hard FK to the old table (see its create migration), so a
 * chargeable_items id can never be written there without violating that
 * constraint. New nullable FK column instead, same shape as
 * 2026_07_24_000014_add_chargeable_item_id_to_consultation_mappings_table.php.
 * The old column stays (nullable already) for historical rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payer_contract_price_overrides', function (Blueprint $table): void {
            $table->uuid('chargeable_item_id')->nullable()->after('billing_service_catalog_item_id');

            $table->foreign('chargeable_item_id')
                ->references('id')
                ->on('chargeable_items')
                ->nullOnDelete();

            $table->index(['chargeable_item_id'], 'billing_payer_contract_price_overrides_chargeable_item_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('billing_payer_contract_price_overrides', function (Blueprint $table): void {
            $table->dropForeign(['chargeable_item_id']);
            $table->dropIndex('billing_payer_contract_price_overrides_chargeable_item_id_idx');
            $table->dropColumn('chargeable_item_id');
        });
    }
};
