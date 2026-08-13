<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_access_audit_logs', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('created_at');
            $table->index(['archived_at', 'created_at'], 'idx_access_audit_logs_archived_at');
        });

        Schema::table('inventory_approval_decisions', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('escalated_at');
            $table->index(['archived_at', 'decided_at'], 'idx_approval_decisions_archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_approval_decisions', function (Blueprint $table): void {
            $table->dropIndex('idx_approval_decisions_archived_at');
            $table->dropColumn('archived_at');
        });

        Schema::table('inventory_access_audit_logs', function (Blueprint $table): void {
            $table->dropIndex('idx_access_audit_logs_archived_at');
            $table->dropColumn('archived_at');
        });
    }
};
