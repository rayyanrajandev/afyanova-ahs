<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('department_stock_balance_id');
            $table->uuid('department_id');
            $table->uuid('item_id');
            $table->uuid('batch_id')->nullable();
            $table->string('movement_type', 30); // issue, consume, return, waste, adjust
            $table->decimal('quantity', 14, 3);
            $table->decimal('quantity_before', 14, 3)->default(0);
            $table->decimal('quantity_after', 14, 3)->default(0);
            $table->string('source', 60)->nullable(); // pharmacy_order, lab_order, manual, return
            $table->string('source_id')->nullable(); // polymorphic
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['department_id', 'item_id']);
            $table->index(['department_stock_balance_id']);
            $table->index(['movement_type']);
            $table->index(['source', 'source_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['occurred_at']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('department_stock_balance_id')
                ->references('id')
                ->on('department_stock_balances')
                ->cascadeOnDelete();

            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->cascadeOnDelete();

            $table->foreign('item_id')
                ->references('id')
                ->on('inventory_items')
                ->cascadeOnDelete();

            $table->foreign('batch_id')
                ->references('id')
                ->on('inventory_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_stock_movements');
    }
};
