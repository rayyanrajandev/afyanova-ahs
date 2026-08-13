<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_item_unit_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('item_id');
            $table->uuid('inventory_item_unit_id');
            $table->string('price_type', 40);
            $table->uuid('billing_payer_contract_id')->nullable();
            $table->decimal('price', 14, 2);
            $table->char('currency_code', 3)->default('TZS');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'price_type', 'is_active']);
            $table->index(['inventory_item_unit_id', 'price_type', 'is_active']);
            $table->index(['billing_payer_contract_id']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->nullOnDelete();
            $table->foreign('item_id')->references('id')->on('inventory_items')->cascadeOnDelete();
            $table->foreign('inventory_item_unit_id')->references('id')->on('inventory_item_units')->cascadeOnDelete();
            $table->foreign('billing_payer_contract_id')->references('id')->on('billing_payer_contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_item_unit_prices');
    }
};