<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the free-text source_location / destination_location columns
 * (added in 2026_04_21_000001) with proper FK-referenced columns so that
 * movements are connected to real system entities rather than typed strings.
 *
 * Movement type → source → destination mapping:
 *   receive   : supplier        → warehouse
 *   issue     : warehouse       → department
 *   transfer  : warehouse       → warehouse (different)
 *   adjust    : (not applicable)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            // Drop the provisional text columns from the previous migration
            if (Schema::hasColumn('inventory_stock_movements', 'source_location')) {
                $table->dropColumn('source_location');
            }
            if (Schema::hasColumn('inventory_stock_movements', 'destination_location')) {
                $table->dropColumn('destination_location');
            }

            // Typed FK columns
            $table->uuid('source_supplier_id')->nullable()->after('adjustment_direction');
            $table->uuid('source_warehouse_id')->nullable()->after('source_supplier_id');
            $table->uuid('destination_warehouse_id')->nullable()->after('source_warehouse_id');
            $table->uuid('destination_department_id')->nullable()->after('destination_warehouse_id');
        });

        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->foreign('source_supplier_id')
                ->references('id')->on('inventory_suppliers')->nullOnDelete();

            $table->foreign('source_warehouse_id')
                ->references('id')->on('inventory_warehouses')->nullOnDelete();

            $table->foreign('destination_warehouse_id')
                ->references('id')->on('inventory_warehouses')->nullOnDelete();

            $table->foreign('destination_department_id')
                ->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->dropForeign(['source_supplier_id']);
            $table->dropForeign(['source_warehouse_id']);
            $table->dropForeign(['destination_warehouse_id']);
            $table->dropForeign(['destination_department_id']);
            $table->dropColumn([
                'source_supplier_id',
                'source_warehouse_id',
                'destination_warehouse_id',
                'destination_department_id',
            ]);

            $table->string('source_location', 500)->nullable()->after('adjustment_direction');
            $table->string('destination_location', 500)->nullable()->after('source_location');
        });
    }
};
