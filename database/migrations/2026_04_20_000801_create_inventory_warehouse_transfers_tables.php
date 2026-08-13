<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_warehouse_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('transfer_number', 60)->unique();

            $table->uuid('source_warehouse_id');
            $table->uuid('destination_warehouse_id');

            $table->string('status', 30)->default('draft')->comment('draft, pending_approval, approved, in_transit, received, cancelled, rejected');
            $table->string('priority', 20)->default('normal');
            $table->text('reason')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->bigInteger('requested_by_user_id')->unsigned()->nullable();
            $table->bigInteger('approved_by_user_id')->unsigned()->nullable();
            $table->bigInteger('dispatched_by_user_id')->unsigned()->nullable();
            $table->bigInteger('received_by_user_id')->unsigned()->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('receiving_notes')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->cascadeOnDelete();
            $table->foreign('source_warehouse_id')->references('id')->on('inventory_warehouses')->cascadeOnDelete();
            $table->foreign('destination_warehouse_id')->references('id')->on('inventory_warehouses')->cascadeOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('dispatched_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('received_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['source_warehouse_id', 'status']);
            $table->index(['destination_warehouse_id', 'status']);
        });

        Schema::create('inventory_warehouse_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transfer_id');
            $table->uuid('item_id');
            $table->uuid('batch_id')->nullable();

            $table->decimal('requested_quantity', 14, 3);
            $table->decimal('dispatched_quantity', 14, 3)->nullable();
            $table->decimal('received_quantity', 14, 3)->nullable();
            $table->string('unit', 40)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('transfer_id')->references('id')->on('inventory_warehouse_transfers')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('inventory_items')->cascadeOnDelete();
            $table->foreign('batch_id')->references('id')->on('inventory_batches')->nullOnDelete();

            $table->index(['transfer_id', 'item_id']);
        });

        Schema::create('inventory_warehouse_transfer_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transfer_id');
            $table->string('action', 100);
            $table->string('actor_type', 30)->default('user');
            $table->bigInteger('actor_id')->unsigned()->nullable();
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('transfer_id')->references('id')->on('inventory_warehouse_transfers')->cascadeOnDelete();
            $table->index(['transfer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_warehouse_transfer_audit_logs');
        Schema::dropIfExists('inventory_warehouse_transfer_lines');
        Schema::dropIfExists('inventory_warehouse_transfers');
    }
};
