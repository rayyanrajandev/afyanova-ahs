<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payer_authorization_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('billing_payer_contract_id');
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('billing_service_catalog_item_id')->nullable();
            $table->string('rule_code', 100);
            $table->string('rule_name', 255);
            $table->string('service_code', 100)->nullable();
            $table->string('service_type', 80)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('diagnosis_code', 40)->nullable();
            $table->string('priority', 20)->nullable();
            $table->unsignedSmallInteger('min_patient_age_years')->nullable();
            $table->unsignedSmallInteger('max_patient_age_years')->nullable();
            $table->string('gender', 20)->nullable();
            $table->decimal('amount_threshold', 14, 2)->nullable();
            $table->unsignedInteger('quantity_limit')->nullable();
            $table->boolean('requires_authorization')->default(true);
            $table->boolean('auto_approve')->default(false);
            $table->unsignedInteger('authorization_validity_days')->nullable();
            $table->text('rule_notes')->nullable();
            $table->json('rule_expression')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 30)->default('active');
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->unique(['billing_payer_contract_id', 'rule_code'], 'billing_payer_authorization_rules_contract_rule_code_unique');
            $table->index(['billing_payer_contract_id', 'status'], 'billing_payer_authorization_rules_contract_status_idx');
            $table->index(['tenant_id', 'status'], 'billing_payer_authorization_rules_tenant_status_idx');
            $table->index(['facility_id', 'status'], 'billing_payer_authorization_rules_facility_status_idx');
            $table->index(['service_code', 'status'], 'billing_payer_authorization_rules_service_code_status_idx');
            $table->index(['service_type', 'status'], 'billing_payer_authorization_rules_service_type_status_idx');

            $table->foreign('billing_payer_contract_id')
                ->references('id')
                ->on('billing_payer_contracts')
                ->cascadeOnDelete();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('billing_service_catalog_item_id')
                ->references('id')
                ->on('billing_service_catalog_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payer_authorization_rules');
    }
};
