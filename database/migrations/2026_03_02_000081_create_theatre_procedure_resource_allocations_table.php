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
        Schema::create('theatre_procedure_resource_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('theatre_procedure_id');
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('resource_type', 40);
            $table->string('resource_reference', 180);
            $table->string('role_label', 120)->nullable();
            $table->timestamp('planned_start_at');
            $table->timestamp('planned_end_at');
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->string('status_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['theatre_procedure_id', 'planned_start_at'], 'thr_resource_alloc_proc_start_idx');
            $table->index(['tenant_id', 'planned_start_at'], 'thr_resource_alloc_tenant_start_idx');
            $table->index(['facility_id', 'planned_start_at'], 'thr_resource_alloc_facility_start_idx');
            $table->index(['status', 'planned_start_at'], 'thr_resource_alloc_status_start_idx');
            $table->index(['resource_type', 'resource_reference'], 'thr_resource_alloc_type_ref_idx');

            $table->foreign('theatre_procedure_id', 'thr_resource_alloc_proc_fk')
                ->references('id')
                ->on('theatre_procedures')
                ->cascadeOnDelete();

            $table->foreign('tenant_id', 'thr_resource_alloc_tenant_fk')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id', 'thr_resource_alloc_facility_fk')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('theatre_procedure_resource_allocations');
    }
};
