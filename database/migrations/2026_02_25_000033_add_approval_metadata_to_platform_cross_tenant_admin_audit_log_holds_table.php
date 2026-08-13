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
        Schema::table('platform_cross_tenant_admin_audit_log_holds', function (Blueprint $table): void {
            $table->string('approval_case_reference', 100)->nullable()->after('reason');
            $table->foreignId('approved_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('review_due_at')->nullable()->after('approved_by_user_id');

            $table->index('approval_case_reference');
            $table->index(['approved_by_user_id', 'review_due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_cross_tenant_admin_audit_log_holds', function (Blueprint $table): void {
            $table->dropIndex(['approved_by_user_id', 'review_due_at']);
            $table->dropIndex(['approval_case_reference']);
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn(['approval_case_reference', 'review_due_at']);
        });
    }
};
