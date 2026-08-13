<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory_MasterData_Alignment_Plan.md Phase 4. A reusable packaging default
 * defined once per Clinical Catalog item (e.g. "Paracetamol 500mg ships as a
 * blister of 10, box of 10 blisters") and copied into inventory_item_units as
 * a one-time seed when a facility creates or links an inventory item to that
 * catalog item -- see CreateInventoryItemUseCase. After that one-time copy,
 * the facility's own inventory_item_units row is independent and can diverge
 * (different local pack sizes are legitimate), which is why this is a
 * *template* table, not a live-joined source the way the clinical descriptor
 * columns in platform_clinical_catalog_items are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_catalog_item_packaging_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('clinical_catalog_item_id');
            $table->string('unit_name', 50);
            $table->string('unit_code', 50)->nullable();
            $table->decimal('base_quantity', 14, 6);
            $table->boolean('is_base_unit')->default(false);
            $table->boolean('is_default_purchase_unit')->default(false);
            $table->boolean('is_default_sales_unit')->default(false);
            $table->timestamps();

            $table->unique(['clinical_catalog_item_id', 'unit_name'], 'catalog_packaging_template_item_unit_unique');
            $table->index('clinical_catalog_item_id', 'catalog_packaging_template_item_idx');

            $table->foreign('clinical_catalog_item_id', 'catalog_packaging_template_item_fk')
                ->references('id')
                ->on('platform_clinical_catalog_items')
                ->cascadeOnDelete();
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE clinical_catalog_item_packaging_templates ADD CONSTRAINT catalog_packaging_template_base_quantity_positive CHECK (base_quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_catalog_item_packaging_templates');
    }
};
