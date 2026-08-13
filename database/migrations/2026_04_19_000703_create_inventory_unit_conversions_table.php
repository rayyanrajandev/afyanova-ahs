<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_unit_conversions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('item_id')->nullable();
            $table->string('from_unit', 40);
            $table->string('to_unit', 40);
            $table->decimal('factor', 14, 6);
            $table->boolean('is_global')->default(false);
            $table->timestamps();

            $table->unique(['item_id', 'from_unit', 'to_unit'], 'inv_unit_conv_item_from_to_unique');
            $table->index(['tenant_id']);
            $table->index(['item_id']);
            $table->index(['is_global']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('item_id')
                ->references('id')
                ->on('inventory_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_unit_conversions');
    }
};
