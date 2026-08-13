<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_stock_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('department_id');
            $table->uuid('item_id');
            $table->uuid('batch_id')->nullable();
            $table->decimal('quantity_on_hand', 14, 3)->default(0);
            $table->decimal('quantity_consumed', 14, 3)->default(0);
            $table->decimal('quantity_returned', 14, 3)->default(0);
            $table->decimal('quantity_wasted', 14, 3)->default(0);
            $table->string('unit', 60)->nullable();
            $table->timestamp('last_issued_at')->nullable();
            $table->timestamp('last_consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'department_id', 'item_id', 'batch_id'], 'dept_stock_bal_unique');
            $table->index(['department_id', 'item_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['item_id']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

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
        Schema::dropIfExists('department_stock_balances');
    }
};
