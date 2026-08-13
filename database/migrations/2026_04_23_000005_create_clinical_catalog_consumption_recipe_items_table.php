<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_catalog_consumption_recipe_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('clinical_catalog_item_id');
            $table->uuid('inventory_item_id');
            $table->decimal('quantity_per_order', 14, 4);
            $table->string('unit', 40);
            $table->decimal('waste_factor_percent', 6, 2)->default(0);
            $table->string('consumption_stage', 40)->default('per_order');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['clinical_catalog_item_id', 'inventory_item_id'],
                'clinical_catalog_recipe_item_unique'
            );
            $table->index(['tenant_id', 'clinical_catalog_item_id'], 'clinical_catalog_recipe_tenant_idx');
            $table->index(['facility_id', 'clinical_catalog_item_id'], 'clinical_catalog_recipe_facility_idx');
            $table->index(['inventory_item_id', 'is_active'], 'clinical_catalog_recipe_inventory_idx');
            $table->index('consumption_stage', 'clinical_catalog_recipe_stage_idx');

            $table->foreign('clinical_catalog_item_id', 'clinical_catalog_recipe_catalog_fk')
                ->references('id')
                ->on('platform_clinical_catalog_items')
                ->cascadeOnDelete();

            $table->foreign('inventory_item_id', 'clinical_catalog_recipe_inventory_fk')
                ->references('id')
                ->on('inventory_items')
                ->restrictOnDelete();

            $table->foreign('tenant_id', 'clinical_catalog_recipe_tenant_fk')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id', 'clinical_catalog_recipe_facility_fk')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_catalog_consumption_recipe_items');
    }
};
