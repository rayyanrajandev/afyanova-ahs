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
            $table->string('release_case_reference', 100)->nullable()->after('release_reason');
            $table->foreignId('release_approved_by_user_id')->nullable()->after('released_by_user_id')->constrained('users')->nullOnDelete();

            $table->index('release_case_reference');
            $table->index('release_approved_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_cross_tenant_admin_audit_log_holds', function (Blueprint $table): void {
            $table->dropIndex(['release_case_reference']);
            $table->dropIndex(['release_approved_by_user_id']);
            $table->dropConstrainedForeignId('release_approved_by_user_id');
            $table->dropColumn('release_case_reference');
        });
    }
};
