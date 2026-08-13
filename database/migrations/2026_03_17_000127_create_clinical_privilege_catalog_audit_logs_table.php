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
        Schema::create('clinical_privilege_catalog_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('privilege_catalog_id')->nullable();
            $table->uuid('tenant_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['privilege_catalog_id', 'created_at'], 'clinical_privilege_catalog_audit_logs_catalog_created_idx');
            $table->index(['tenant_id', 'created_at'], 'clinical_privilege_catalog_audit_logs_tenant_created_idx');
            $table->index(['action', 'created_at'], 'clinical_privilege_catalog_audit_logs_action_created_idx');

            $table->foreign('privilege_catalog_id')
                ->references('id')
                ->on('clinical_privilege_catalogs')
                ->nullOnDelete();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinical_privilege_catalog_audit_logs');
    }
};
