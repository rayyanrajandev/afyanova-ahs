<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_cross_tenant_admin_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action', 100);
            $table->string('operation_type', 20);
            $table->foreignId('actor_id')->nullable()->index();
            $table->uuid('target_tenant_id')->nullable();
            $table->string('target_tenant_code', 32)->nullable();
            $table->string('target_resource_type', 50)->nullable();
            $table->string('target_resource_id', 64)->nullable();
            $table->string('outcome', 30)->default('success');
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_tenant_id', 'created_at']);
            $table->index(['target_tenant_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_cross_tenant_admin_audit_logs');
    }
};
