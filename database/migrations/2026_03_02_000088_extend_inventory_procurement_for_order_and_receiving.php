<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_procurement_requests', function (Blueprint $table): void {
            $table->string('purchase_order_number', 100)->nullable()->after('request_number');
            $table->decimal('ordered_quantity', 14, 3)->nullable()->after('requested_quantity');
            $table->decimal('received_quantity', 14, 3)->nullable()->after('ordered_quantity');
            $table->decimal('received_unit_cost', 14, 2)->nullable()->after('unit_cost_estimate');
            $table->uuid('receiving_warehouse_id')->nullable()->after('facility_id');
            $table->text('receiving_notes')->nullable()->after('notes');

            $table->index(['purchase_order_number']);
            $table->index(['receiving_warehouse_id']);

            $table->foreign('receiving_warehouse_id')
                ->references('id')
                ->on('inventory_warehouses')
                ->nullOnDelete();
        });

        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->uuid('procurement_request_id')->nullable()->after('item_id');
            $table->index(['procurement_request_id', 'occurred_at']);

            $table->foreign('procurement_request_id')
                ->references('id')
                ->on('inventory_procurement_requests')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->dropForeign(['procurement_request_id']);
            $table->dropIndex(['procurement_request_id', 'occurred_at']);
            $table->dropColumn('procurement_request_id');
        });

        Schema::table('inventory_procurement_requests', function (Blueprint $table): void {
            $table->dropForeign(['receiving_warehouse_id']);
            $table->dropIndex(['purchase_order_number']);
            $table->dropIndex(['receiving_warehouse_id']);
            $table->dropColumn([
                'purchase_order_number',
                'ordered_quantity',
                'received_quantity',
                'received_unit_cost',
                'receiving_warehouse_id',
                'receiving_notes',
            ]);
        });
    }
};

