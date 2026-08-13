<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_service_catalog_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('service_code', 100);
            $table->string('service_name', 255);
            $table->string('service_type', 80)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('unit', 50)->default('service');
            $table->decimal('base_price', 14, 2);
            $table->char('currency_code', 3)->default('TZS');
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            $table->boolean('is_taxable')->default(false);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 30)->default('active');
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'service_type']);
            $table->index(['facility_id', 'service_type']);
            $table->index(['status', 'updated_at']);
            $table->index(['department', 'status']);
            $table->index(['currency_code', 'status']);
            $table->unique(['tenant_id', 'facility_id', 'service_code'], 'billing_service_catalog_items_tenant_facility_code_unique');

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
        Schema::dropIfExists('billing_service_catalog_items');
    }
};
