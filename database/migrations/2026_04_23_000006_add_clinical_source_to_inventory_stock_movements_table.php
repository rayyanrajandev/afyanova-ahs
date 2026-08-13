<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_stock_movements', 'source_type')) {
                $table->string('source_type', 80)->nullable()->after('item_id');
                $table->uuid('source_id')->nullable()->after('source_type');
                $table->uuid('clinical_catalog_item_id')->nullable()->after('source_id');
                $table->uuid('consumption_recipe_item_id')->nullable()->after('clinical_catalog_item_id');

                $table->index(['source_type', 'source_id'], 'inventory_stock_movements_source_idx');
                $table->index('clinical_catalog_item_id', 'inventory_stock_movements_catalog_item_idx');
                $table->index('consumption_recipe_item_id', 'inventory_stock_movements_recipe_item_idx');
                $table->unique(
                    ['source_type', 'source_id', 'consumption_recipe_item_id'],
                    'inventory_stock_movements_recipe_source_unique'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_stock_movements', 'source_type')) {
                $table->dropUnique('inventory_stock_movements_recipe_source_unique');
                $table->dropIndex('inventory_stock_movements_recipe_item_idx');
                $table->dropIndex('inventory_stock_movements_catalog_item_idx');
                $table->dropIndex('inventory_stock_movements_source_idx');
                $table->dropColumn([
                    'source_type',
                    'source_id',
                    'clinical_catalog_item_id',
                    'consumption_recipe_item_id',
                ]);
            }
        });
    }
};
