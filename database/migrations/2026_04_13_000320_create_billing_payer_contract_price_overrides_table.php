<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payer_contract_price_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('billing_payer_contract_id');
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('billing_service_catalog_item_id')->nullable();
            $table->string('service_code', 100);
            $table->string('service_name', 255)->nullable();
            $table->string('service_type', 80)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('currency_code', 3);
            $table->string('pricing_strategy', 40)->default('fixed_price');
            $table->decimal('override_value', 14, 2);
            $table->dateTime('effective_from')->nullable();
            $table->dateTime('effective_to')->nullable();
            $table->text('override_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 30)->default('active');
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->index(['billing_payer_contract_id', 'status'], 'billing_payer_contract_price_overrides_contract_status_idx');
            $table->index(['billing_payer_contract_id', 'service_code'], 'billing_payer_contract_price_overrides_contract_service_code_idx');
            $table->index(['tenant_id', 'status'], 'billing_payer_contract_price_overrides_tenant_status_idx');
            $table->index(['facility_id', 'status'], 'billing_payer_contract_price_overrides_facility_status_idx');
            $table->index(['service_type', 'status'], 'billing_payer_contract_price_overrides_service_type_status_idx');

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
        Schema::dropIfExists('billing_payer_contract_price_overrides');
    }
};
