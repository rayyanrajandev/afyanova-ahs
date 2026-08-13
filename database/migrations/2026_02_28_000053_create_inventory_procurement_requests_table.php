<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_procurement_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('request_number', 60)->unique();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('item_id');
            $table->decimal('requested_quantity', 14, 3);
            $table->decimal('unit_cost_estimate', 14, 2)->nullable();
            $table->decimal('total_cost_estimate', 14, 2)->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->string('status', 30)->default('pending_approval');
            $table->string('status_reason')->nullable();
            $table->date('needed_by')->nullable();
            $table->string('supplier_name', 180)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['facility_id', 'created_at']);
            $table->index(['item_id', 'created_at']);
            $table->index(['status', 'created_at']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('item_id')
                ->references('id')
                ->on('inventory_items')
                ->cascadeOnDelete();

            $table->foreign('requested_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('approved_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_procurement_requests');
    }
};
