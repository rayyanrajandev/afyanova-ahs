<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_msd_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->string('msd_order_number', 50)->unique();
            $table->string('facility_msd_code', 50)->nullable()->comment('MSD facility/customer code');
            $table->uuid('procurement_request_id')->nullable()->comment('Links to internal procurement request');
            $table->uuid('supplier_id')->nullable()->comment('Links to inventory_suppliers.id for MSD supplier');

            // Order details
            $table->json('order_lines')->comment('Array of {msd_code, item_name, quantity, unit, unit_cost}');
            $table->string('currency_code', 10)->default('TZS');
            $table->decimal('total_amount', 16, 4)->nullable();
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->date('actual_delivery_date')->nullable();

            // MSD e-ordering status
            $table->string('status', 50)->default('draft');
            $table->string('submission_reference', 255)->nullable()->comment('Reference returned by MSD API');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_note_number', 100)->nullable();

            // Tracking
            $table->string('rejection_reason', 500)->nullable();
            $table->json('api_response_log')->nullable()->comment('Last MSD API response for debugging');
            $table->json('metadata')->nullable();
            $table->string('notes', 1000)->nullable();

            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
            $table->index('procurement_request_id');
            $table->index('supplier_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_msd_orders');
    }
};
