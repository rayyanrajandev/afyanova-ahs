<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NHIF e-Claims submissions tracking
        Schema::create('billing_nhif_claim_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('claims_insurance_case_id')->index();
            $table->uuid('billing_invoice_id')->index();
            $table->string('nhif_claim_reference', 100)->nullable()->unique();
            $table->string('submission_status', 30)->default('draft');
            $table->decimal('submitted_amount', 15, 2)->nullable();
            $table->json('claim_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
            $table->index(['submission_status', 'created_at']);
        });

        // Patient payment links (M-Pesa self-service)
        Schema::create('billing_payment_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('billing_invoice_id')->index();
            $table->uuid('patient_id')->index();
            $table->string('phone_number', 20)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('TZS');
            $table->string('reference_code', 50)->unique();
            $table->string('status', 30)->default('pending');
            $table->string('gateway_transaction_id', 100)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
            $table->index(['status', 'expires_at']);
        });

        // NHIF tariff import log
        Schema::create('billing_nhif_tariff_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->string('tariff_version', 50);
            $table->date('effective_date');
            $table->integer('items_imported')->default(0);
            $table->integer('items_updated')->default(0);
            $table->integer('items_skipped')->default(0);
            $table->json('import_log')->nullable();
            $table->string('status', 30)->default('completed');
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
            $table->index(['tariff_version', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_nhif_tariff_imports');
        Schema::dropIfExists('billing_payment_links');
        Schema::dropIfExists('billing_nhif_claim_submissions');
    }
};
