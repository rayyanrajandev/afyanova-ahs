<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('item_id');
            $table->uuid('batch_id')->nullable();
            $table->uuid('warehouse_id')->nullable();
            $table->string('source_type', 80);
            $table->uuid('source_id');
            $table->uuid('source_line_id')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->string('status', 20)->default('active')->comment('active, consumed, released');
            $table->bigInteger('reserved_by_user_id')->unsigned()->nullable();
            $table->bigInteger('consumed_by_user_id')->unsigned()->nullable();
            $table->bigInteger('released_by_user_id')->unsigned()->nullable();
            $table->timestamp('reserved_at')->useCurrent();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('inventory_items')->cascadeOnDelete();
            $table->foreign('batch_id')->references('id')->on('inventory_batches')->nullOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('inventory_warehouses')->nullOnDelete();
            $table->foreign('reserved_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('consumed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('released_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['status', 'item_id']);
            $table->index(['status', 'batch_id']);
            $table->index(['status', 'warehouse_id']);
            $table->index(['source_type', 'source_id', 'source_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_reservations');
    }
};
