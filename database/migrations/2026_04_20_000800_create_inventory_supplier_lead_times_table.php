<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_supplier_lead_times', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('supplier_id');
            $table->uuid('item_id')->nullable()->comment('NULL = supplier-level record, non-null = item-specific');
            $table->uuid('procurement_request_id')->nullable();

            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->date('actual_delivery_date')->nullable();
            $table->integer('expected_lead_time_days')->nullable();
            $table->integer('actual_lead_time_days')->nullable();
            $table->decimal('quantity_ordered', 14, 3)->nullable();
            $table->decimal('quantity_received', 14, 3)->nullable();
            $table->decimal('fulfillment_rate', 5, 2)->nullable()->comment('Percent: qty received / qty ordered * 100');
            $table->string('delivery_status', 30)->default('pending')->comment('pending, on_time, late, partial, cancelled');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->cascadeOnDelete();
            $table->foreign('supplier_id')->references('id')->on('inventory_suppliers')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('inventory_items')->nullOnDelete();
            $table->foreign('procurement_request_id')->references('id')->on('inventory_procurement_requests')->nullOnDelete();

            $table->index(['supplier_id', 'item_id', 'order_date']);
            $table->index(['tenant_id', 'supplier_id', 'delivery_status']);
            $table->index(['supplier_id', 'actual_lead_time_days']);
        });

        // Add lead-time summary columns to suppliers table
        Schema::table('inventory_suppliers', function (Blueprint $table) {
            $table->decimal('avg_lead_time_days', 8, 1)->nullable()->after('notes');
            $table->decimal('avg_fulfillment_rate', 5, 2)->nullable()->after('avg_lead_time_days');
            $table->unsignedInteger('total_deliveries')->default(0)->after('avg_fulfillment_rate');
            $table->unsignedInteger('on_time_deliveries')->default(0)->after('total_deliveries');
            $table->timestamp('lead_time_last_calculated_at')->nullable()->after('on_time_deliveries');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'avg_lead_time_days',
                'avg_fulfillment_rate',
                'total_deliveries',
                'on_time_deliveries',
                'lead_time_last_calculated_at',
            ]);
        });

        Schema::dropIfExists('inventory_supplier_lead_times');
    }
};
