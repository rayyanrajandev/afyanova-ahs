<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_book_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('chargeable_item_id');
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('facility_tier', 40)->nullable();
            $table->uuid('payer_contract_id')->nullable();
            $table->char('currency_code', 3)->default('TZS');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            $table->boolean('is_taxable')->default(false);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->unsignedInteger('tariff_version')->default(1);
            $table->string('status', 30)->default('active');
            $table->string('status_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['chargeable_item_id', 'status', 'effective_from'], 'price_book_entries_item_status_effective_idx');
            $table->index(['currency_code', 'status']);
            $table->index(['payer_contract_id', 'status']);

            $table->foreign('chargeable_item_id')
                ->references('id')
                ->on('chargeable_items')
                ->cascadeOnDelete();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('payer_contract_id')
                ->references('id')
                ->on('billing_payer_contracts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_book_entries');
    }
};
