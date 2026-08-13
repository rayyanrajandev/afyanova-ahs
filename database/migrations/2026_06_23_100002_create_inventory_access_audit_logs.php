<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create inventory access audit logs table for compliance tracking
     * Phase 1: Department-Level RBAC Implementation
     */
    public function up(): void
    {
        Schema::create('inventory_access_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            
            // Tenant and organizational context
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('department_id')->nullable()->index();
            
            // Action metadata
            $table->string('action', 100);
            $table->uuid('actor_id')->nullable();
            $table->string('actor_department', 150)->nullable();
            $table->timestamp('action_timestamp')->useCurrent()->index();
            
            // Resource metadata
            $table->string('resource_type', 50);
            $table->uuid('resource_id')->nullable()->index();
            $table->string('resource_name', 150)->nullable();
            
            // Target metadata (for user-related actions)
            $table->uuid('target_user_id')->nullable();
            $table->uuid('target_role_id')->nullable();
            
            // Change tracking
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('changes')->nullable();
            
            // Business context
            $table->json('business_context')->nullable();
            
            // Access decision
            $table->enum('access_decision', ['permit', 'deny'])->nullable();
            $table->string('deny_reason', 255)->nullable();
            $table->json('permissions_checked')->nullable();
            
            // Compliance
            $table->json('compliance_flags')->nullable();
            
            // Immutable timestamp (no updates allowed)
            $table->timestamp('created_at')->useCurrent();
            
            // Composite indices for common queries
            $table->index(['tenant_id', 'facility_id', 'resource_type']);
            $table->index(['actor_id', 'action_timestamp']);
            $table->index(['resource_id', 'action_timestamp']);
            $table->index('action');
            $table->index(['access_decision', 'action_timestamp']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_access_audit_logs');
    }
};
