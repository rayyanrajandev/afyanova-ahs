<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add department-level scoping to RBAC roles for inventory access control
     * Phase 1: Department-Level RBAC Implementation
     */
    public function up(): void
    {
        // Add department scoping to roles table
        Schema::table('roles', function (Blueprint $table): void {
            // Department-level scoping
            $table->uuid('department_id')->nullable()->after('facility_id');
            
            // Access level granularity (view, request, approve, manage)
            // Enforced at application layer for cross-DB compatibility
            $table->string('access_level', 30)
                ->nullable()
                ->after('is_system');
            
            // Scope type for cross-department/facility access
            // Enforced at application layer for cross-DB compatibility
            $table->string('scope_type', 40)
                ->default('own_department')
                ->after('access_level');
            
            // Temporal access control
            // nullable to support SQLite ALTER TABLE (no non-constant defaults)
            $table->timestamp('effective_from')
                ->nullable()
                ->after('status');
            
            $table->timestamp('effective_until')
                ->nullable()
                ->after('effective_from');
            
            // Revocation tracking
            $table->timestamp('revoked_at')
                ->nullable()
                ->after('effective_until');
            
            $table->string('revocation_reason', 500)
                ->nullable()
                ->after('revoked_at');
            
            // JSON field for related departments (for scope_type='related_departments')
            $table->json('related_department_ids')
                ->nullable()
                ->after('revocation_reason');
            
            // Foreign key for department
            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->nullOnDelete();
            
            // Indices for performance
            $table->index(['tenant_id', 'facility_id', 'department_id', 'access_level']);
            $table->index(['effective_until', 'revoked_at']);
            $table->index(['scope_type', 'status']);
        });

        // Add department scoping to inventory_warehouses table
        Schema::table('inventory_warehouses', function (Blueprint $table): void {
            $table->uuid('department_id')->nullable()->after('facility_id');
            $table->boolean('is_default')->default(false)->after('notes');
            
            // Foreign key for department
            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->nullOnDelete();
            
            // Index for department-scoped queries
            $table->index(['tenant_id', 'department_id']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table): void {
            $table->dropForeign(['department_id']);
            $table->dropIndex(['tenant_id', 'department_id']);
            $table->dropColumn(['department_id', 'is_default']);
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropForeign(['department_id']);
            $table->dropIndex(['tenant_id', 'facility_id', 'department_id', 'access_level']);
            $table->dropIndex(['effective_until', 'revoked_at']);
            $table->dropIndex(['scope_type', 'status']);
            
            $table->dropColumn([
                'department_id',
                'access_level',
                'scope_type',
                'effective_from',
                'effective_until',
                'revoked_at',
                'revocation_reason',
                'related_department_ids',
            ]);
        });
    }
};

