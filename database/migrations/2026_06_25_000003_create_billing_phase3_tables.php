<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NHIF remittance advice records
        Schema::create('billing_nhif_remittances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->string('remittance_reference', 100)->index();
            $table->date('remittance_date');
            $table->string('payer_name', 200)->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->integer('total_claims')->default(0);
            $table->integer('matched_claims')->default(0);
            $table->decimal('matched_amount', 15, 2)->default(0);
            $table->decimal('unmatched_amount', 15, 2)->default(0);
            $table->string('source', 20)->default('upload');
            $table->string('original_filename', 255)->nullable();
            $table->json('raw_data')->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'facility_id', 'remittance_reference']);
        });

        // Individual line items within a remittance advice
        Schema::create('billing_nhif_remittance_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('billing_nhif_remittance_id')->index();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->string('claim_reference', 100)->index();
            $table->string('member_number', 50)->nullable();
            $table->string('patient_name', 200)->nullable();
            $table->decimal('claimed_amount', 15, 2)->default(0);
            $table->decimal('approved_amount', 15, 2)->default(0);
            $table->decimal('rejected_amount', 15, 2)->default(0);
            $table->decimal('settled_amount', 15, 2)->default(0);
            $table->string('decision', 30)->nullable();
            $table->text('decision_reason')->nullable();
            $table->json('raw_data')->nullable();
            $table->string('reconciliation_status', 30)->default('unmatched');
            $table->uuid('matched_claim_submission_id')->nullable()->index();
            $table->uuid('matched_claims_insurance_case_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('billing_nhif_remittance_id')
                ->references('id')->on('billing_nhif_remittances')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'facility_id']);
        });

        // SMS notification log
        Schema::create('billing_sms_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->string('phone_number', 20);
            $table->string('message_type', 50)->index();
            $table->text('message');
            $table->string('provider', 50)->default('africastalking');
            $table->string('provider_message_id', 100)->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('error_message')->nullable();
            $table->json('context')->nullable();
            $table->uuid('billing_invoice_id')->nullable()->index();
            $table->uuid('billing_payment_link_id')->nullable()->index();
            $table->uuid('patient_id')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_sms_logs');
        Schema::dropIfExists('billing_nhif_remittance_items');
        Schema::dropIfExists('billing_nhif_remittances');
    }
};
