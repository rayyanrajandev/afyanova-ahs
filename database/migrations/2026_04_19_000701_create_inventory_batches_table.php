<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('item_id');
            $table->string('batch_number', 100);
            $table->string('lot_number', 100)->nullable();
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->uuid('warehouse_id')->nullable();
            $table->string('bin_location', 60)->nullable();
            $table->uuid('supplier_id')->nullable();
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->string('status', 20)->default('available');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'batch_number', 'warehouse_id'], 'inv_batch_item_batch_wh_unique');
            $table->index(['tenant_id', 'item_id']);
            $table->index(['facility_id', 'item_id']);
            $table->index(['expiry_date']);
            $table->index(['status', 'expiry_date']);
            $table->index(['warehouse_id']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('item_id')
                ->references('id')
                ->on('inventory_items')
                ->cascadeOnDelete();

            $table->foreign('warehouse_id')
                ->references('id')
                ->on('inventory_warehouses')
                ->nullOnDelete();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('inventory_suppliers')
                ->nullOnDelete();
        });

        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->uuid('batch_id')->nullable()->after('item_id');

            $table->index(['batch_id', 'occurred_at']);

            $table->foreign('batch_id')
                ->references('id')
                ->on('inventory_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->dropIndex(['batch_id', 'occurred_at']);
            $table->dropColumn('batch_id');
        });

        Schema::dropIfExists('inventory_batches');
    }
};
