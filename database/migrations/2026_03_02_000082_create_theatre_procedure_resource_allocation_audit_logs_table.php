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
        Schema::create('theatre_procedure_resource_allocation_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('theatre_procedure_resource_allocation_id');
            $table->uuid('theatre_procedure_id');
            $table->string('action');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['theatre_procedure_resource_allocation_id', 'created_at'], 'thr_res_alloc_audit_alloc_created_idx');
            $table->index(['theatre_procedure_id', 'created_at'], 'thr_res_alloc_audit_proc_created_idx');
            $table->index(['action', 'created_at'], 'thr_res_alloc_audit_action_created_idx');
            $table->index(['actor_id', 'created_at'], 'thr_res_alloc_audit_actor_created_idx');

            $table->foreign('theatre_procedure_resource_allocation_id', 'thr_res_alloc_audit_alloc_fk')
                ->references('id')
                ->on('theatre_procedure_resource_allocations')
                ->cascadeOnDelete();

            $table->foreign('theatre_procedure_id', 'thr_res_alloc_audit_proc_fk')
                ->references('id')
                ->on('theatre_procedures')
                ->cascadeOnDelete();

            $table->foreign('actor_id', 'thr_res_alloc_audit_actor_fk')
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
        Schema::dropIfExists('theatre_procedure_resource_allocation_audit_logs');
    }
};
