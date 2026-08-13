<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payment_gateway_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('billing_invoice_id')->nullable()->index();
            $table->uuid('billing_invoice_payment_id')->nullable()->index();
            $table->string('gateway', 50)->default('selcom');
            $table->string('transaction_reference', 100)->unique();
            $table->string('provider_reference', 100)->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->string('amount', 20);
            $table->string('currency', 3)->default('TZS');
            $table->string('status', 30)->default('pending');
            $table->string('description')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('billing_nhif_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('patient_id')->nullable()->index();
            $table->uuid('patient_insurance_record_id')->nullable()->index();
            $table->string('member_id', 100)->index();
            $table->string('card_status', 30)->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('member_name')->nullable();
            $table->string('plan_name')->nullable();
            $table->string('employer_name')->nullable();
            $table->string('effective_date', 20)->nullable();
            $table->string('expiry_date', 20)->nullable();
            $table->decimal('outstanding_balance', 15, 2)->nullable();
            $table->json('dependants')->nullable();
            $table->json('raw_response')->nullable();
            $table->string('source', 30)->default('api');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
            $table->index(['member_id', 'created_at']);
        });

        Schema::create('billing_tra_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('billing_invoice_id')->nullable()->index();
            $table->uuid('billing_invoice_payment_id')->nullable()->index();
            $table->string('reference_number', 100)->index();
            $table->string('rctvnum', 100)->unique();
            $table->string('verification_link')->nullable();
            $table->string('local_date', 20)->nullable();
            $table->string('local_time', 20)->nullable();
            $table->integer('gc')->nullable();
            $table->integer('dc')->nullable();
            $table->string('z_number', 30)->nullable();
            $table->decimal('total_incl_tax', 15, 2)->nullable();
            $table->decimal('total_tax', 15, 2)->nullable();
            $table->json('raw_response')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
            $table->index(['rctvnum', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_tra_receipts');
        Schema::dropIfExists('billing_nhif_verifications');
        Schema::dropIfExists('billing_payment_gateway_transactions');
    }
};
