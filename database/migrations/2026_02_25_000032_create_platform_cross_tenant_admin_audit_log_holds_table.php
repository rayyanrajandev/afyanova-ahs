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
        Schema::create('platform_cross_tenant_admin_audit_log_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('hold_code', 64)->unique();
            $table->string('reason', 255);
            $table->string('target_tenant_code', 32)->nullable();
            $table->string('action', 100)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['is_active', 'released_at']);
            $table->index(['target_tenant_code', 'action']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_cross_tenant_admin_audit_log_holds');
    }
};
