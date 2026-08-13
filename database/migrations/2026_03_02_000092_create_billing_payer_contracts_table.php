<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payer_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('contract_code', 100);
            $table->string('contract_name', 255);
            $table->string('payer_type', 40);
            $table->string('payer_name', 160);
            $table->string('payer_plan_code', 120)->nullable();
            $table->string('payer_plan_name', 160)->nullable();
            $table->char('currency_code', 3)->default('TZS');
            $table->decimal('default_coverage_percent', 5, 2)->nullable();
            $table->string('default_copay_type', 20)->nullable();
            $table->decimal('default_copay_value', 12, 2)->nullable();
            $table->boolean('requires_pre_authorization')->default(false);
            $table->unsignedInteger('claim_submission_deadline_days')->nullable();
            $table->unsignedInteger('settlement_cycle_days')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->text('terms_and_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 30)->default('active');
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'facility_id', 'contract_code'], 'billing_payer_contracts_tenant_facility_code_unique');
            $table->index(['tenant_id', 'payer_type']);
            $table->index(['facility_id', 'payer_type']);
            $table->index(['status', 'updated_at']);
            $table->index(['payer_name', 'status']);
            $table->index(['effective_from', 'effective_to']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payer_contracts');
    }
};
