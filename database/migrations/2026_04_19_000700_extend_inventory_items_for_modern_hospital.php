<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->string('subcategory', 120)->nullable()->after('category');
            $table->string('ven_classification', 20)->nullable()->after('subcategory');
            $table->string('abc_classification', 1)->nullable()->after('ven_classification');
            $table->string('msd_code', 60)->nullable()->after('item_code');
            $table->string('nhif_code', 60)->nullable()->after('msd_code');
            $table->string('barcode', 100)->nullable()->after('nhif_code');
            $table->string('manufacturer', 180)->nullable()->after('unit');
            $table->string('generic_name', 180)->nullable()->after('item_name');
            $table->string('dosage_form', 60)->nullable()->after('generic_name');
            $table->string('strength', 60)->nullable()->after('dosage_form');
            $table->string('storage_conditions', 60)->nullable()->after('manufacturer');
            $table->boolean('requires_cold_chain')->default(false)->after('storage_conditions');
            $table->boolean('is_controlled_substance')->default(false)->after('requires_cold_chain');
            $table->string('controlled_substance_schedule', 20)->nullable()->after('is_controlled_substance');
            $table->string('dispensing_unit', 40)->nullable()->after('unit');
            $table->decimal('conversion_factor', 12, 4)->nullable()->after('dispensing_unit');
            $table->string('bin_location', 60)->nullable()->after('conversion_factor');
            $table->uuid('default_warehouse_id')->nullable()->after('facility_id');
            $table->uuid('default_supplier_id')->nullable()->after('default_warehouse_id');

            $table->index(['msd_code']);
            $table->index(['nhif_code']);
            $table->index(['barcode']);
            $table->index(['ven_classification']);
            $table->index(['is_controlled_substance']);
            $table->index(['default_warehouse_id']);

            $table->foreign('default_warehouse_id')
                ->references('id')
                ->on('inventory_warehouses')
                ->nullOnDelete();

            $table->foreign('default_supplier_id')
                ->references('id')
                ->on('inventory_suppliers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropForeign(['default_warehouse_id']);
            $table->dropForeign(['default_supplier_id']);
            $table->dropIndex(['msd_code']);
            $table->dropIndex(['nhif_code']);
            $table->dropIndex(['barcode']);
            $table->dropIndex(['ven_classification']);
            $table->dropIndex(['is_controlled_substance']);
            $table->dropIndex(['default_warehouse_id']);
            $table->dropColumn([
                'subcategory',
                'ven_classification',
                'abc_classification',
                'msd_code',
                'nhif_code',
                'barcode',
                'manufacturer',
                'generic_name',
                'dosage_form',
                'strength',
                'storage_conditions',
                'requires_cold_chain',
                'is_controlled_substance',
                'controlled_substance_schedule',
                'dispensing_unit',
                'conversion_factor',
                'bin_location',
                'default_warehouse_id',
                'default_supplier_id',
            ]);
        });
    }
};
