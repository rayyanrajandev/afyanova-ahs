<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_department_requisitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('requisition_number', 60)->unique();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('requesting_department', 120);
            $table->string('issuing_store', 120)->nullable();
            $table->uuid('issuing_warehouse_id')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->unsignedBigInteger('issued_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->date('needed_by')->nullable();
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['facility_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['requesting_department', 'created_at']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('issuing_warehouse_id')
                ->references('id')
                ->on('inventory_warehouses')
                ->nullOnDelete();

            $table->foreign('requested_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('approved_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('issued_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::create('inventory_department_requisition_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('requisition_id');
            $table->uuid('item_id');
            $table->uuid('batch_id')->nullable();
            $table->decimal('requested_quantity', 14, 3);
            $table->decimal('approved_quantity', 14, 3)->nullable();
            $table->decimal('issued_quantity', 14, 3)->nullable();
            $table->string('unit', 40);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['requisition_id']);
            $table->index(['item_id']);

            $table->foreign('requisition_id')
                ->references('id')
                ->on('inventory_department_requisitions')
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

        Schema::create('inventory_department_requisition_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('requisition_id');
            $table->string('action', 100);
            $table->string('actor_type', 40)->default('user');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['requisition_id', 'created_at']);

            $table->foreign('requisition_id')
                ->references('id')
                ->on('inventory_department_requisitions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_department_requisition_audit_logs');
        Schema::dropIfExists('inventory_department_requisition_lines');
        Schema::dropIfExists('inventory_department_requisitions');
    }
};
