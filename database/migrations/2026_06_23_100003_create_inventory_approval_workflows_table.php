<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Approval workflow configuration (defines approval rules per department/role)
        Schema::create('inventory_approval_workflows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('department_id')->nullable();
            
            $table->string('code', 100); // e.g., PHARMACY_STANDARD, LAB_URGENT
            $table->string('name', 255);
            $table->text('description')->nullable();
            
            // Workflow configuration
            $table->string('trigger_type', 50); // requisition, transfer, adjustment
            $table->json('trigger_rules')->nullable(); // Rules that determine when this workflow applies
            $table->json('approval_steps')->nullable(); // Array of approval step definitions
            
            $table->string('status', 30)->default('active'); // active, inactive, archived
            $table->timestamps();
            
            $table->index(['tenant_id', 'facility_id', 'department_id']);
            $table->index(['trigger_type', 'status']);
            $table->unique(['tenant_id', 'facility_id', 'code']);
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->cascadeOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });

        // Approval workflow instances (specific requisitions going through workflow)
        Schema::create('inventory_approval_workflow_instances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workflow_id');
            $table->uuid('requisition_id');
            
            // Current state
            $table->string('current_step', 50); // step_1, step_2, approved, rejected
            $table->integer('step_number')->default(1);
            $table->integer('total_steps');
            $table->string('status', 30); // in_progress, approved, rejected, recalled
            
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['tenant_id', 'workflow_id']);
            $table->index(['requisition_id']);
            $table->index(['status', 'current_step']);
            $table->index(['started_at', 'completed_at']);
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_id')->references('id')->on('inventory_approval_workflows')->cascadeOnDelete();
            $table->foreign('requisition_id')->references('id')->on('inventory_department_requisitions')->cascadeOnDelete();
        });

        // Individual approval decisions at each step
        Schema::create('inventory_approval_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workflow_instance_id');
            $table->unsignedBigInteger('approver_user_id');
            
            // Decision context
            $table->integer('step_number');
            $table->string('step_type', 50); // manager, director, executive
            $table->string('decision', 30); // approved, rejected, recalled
            $table->text('comments')->nullable();
            
            // Compliance context
            $table->uuid('approver_department_id')->nullable();
            $table->string('approver_job_title', 255)->nullable();
            
            // Segregation of duties tracking
            $table->unsignedBigInteger('requisition_requester_id');
            $table->boolean('sod_violation_flagged')->default(false);
            $table->text('sod_violation_reason')->nullable();
            
            // Temporal context
            $table->timestamp('decided_at')->useCurrent();
            $table->timestamp('escalated_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['tenant_id', 'workflow_instance_id']);
            $table->index(['approver_user_id']);
            $table->index(['step_number', 'decision']);
            $table->index(['sod_violation_flagged', 'decided_at']);
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_instance_id')->references('id')->on('inventory_approval_workflow_instances')->cascadeOnDelete();
            $table->foreign('approver_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('approver_department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('requisition_requester_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Approval rules per role/department (defines who can approve what)
        Schema::create('inventory_approval_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('department_id')->nullable();
            $table->uuid('role_id')->nullable();
            
            // Rule definition
            $table->string('approval_type', 50); // manager, director, executive, finance
            $table->json('approval_permissions')->nullable(); // { can_approve_own_dept: true, max_amount: 50000 }
            
            // Approval authority bounds
            $table->integer('max_requisition_amount')->nullable();
            $table->integer('max_items_count')->nullable();
            $table->json('allowed_categories')->nullable(); // null = all
            
            $table->string('status', 30)->default('active');
            $table->timestamps();
            
            $table->index(['tenant_id', 'facility_id', 'department_id']);
            $table->index(['approval_type', 'status']);
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->cascadeOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_approval_rules');
        Schema::dropIfExists('inventory_approval_decisions');
        Schema::dropIfExists('inventory_approval_workflow_instances');
        Schema::dropIfExists('inventory_approval_workflows');
    }
};
