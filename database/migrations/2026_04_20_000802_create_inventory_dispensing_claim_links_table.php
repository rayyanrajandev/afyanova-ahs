<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_dispensing_claim_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            // Dispensing side — pharmacy order / stock movement
            $table->uuid('stock_movement_id')->nullable()->comment('Links to the issue stock movement');
            $table->uuid('pharmacy_order_id')->nullable()->comment('Links to the pharmacy order that triggered dispensing');
            $table->uuid('item_id')->comment('Inventory item dispensed');
            $table->uuid('batch_id')->nullable();
            $table->decimal('quantity_dispensed', 12, 3);
            $table->string('unit', 50)->nullable();
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('total_cost', 14, 4)->nullable();

            // Patient / encounter context
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable();
            $table->uuid('appointment_id')->nullable();

            // Insurance / claim side
            $table->uuid('insurance_claim_id')->nullable()->comment('Links to claims_insurance_cases.id');
            $table->uuid('billing_invoice_id')->nullable()->comment('Links to billing_invoices.id');
            $table->string('nhif_code', 50)->nullable()->comment('NHIF item code for claim submission');
            $table->string('payer_type', 50)->nullable();
            $table->string('payer_name', 255)->nullable();
            $table->string('payer_reference', 255)->nullable();

            // Claim status tracking
            $table->string('claim_status', 50)->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('adjudicated_at')->nullable();
            $table->decimal('approved_amount', 14, 4)->nullable();
            $table->decimal('rejected_amount', 14, 4)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->string('notes', 1000)->nullable();

            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
            $table->index('item_id');
            $table->index('patient_id');
            $table->index('insurance_claim_id');
            $table->index('billing_invoice_id');
            $table->index('claim_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_dispensing_claim_links');
    }
};
