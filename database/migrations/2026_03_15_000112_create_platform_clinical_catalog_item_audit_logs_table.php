<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_clinical_catalog_item_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('platform_clinical_catalog_item_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 120);
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('platform_clinical_catalog_item_id', 'platform_clinical_catalog_item_audit_logs_item_idx');
            $table->index('action', 'platform_clinical_catalog_item_audit_logs_action_idx');
            $table->index('actor_id', 'platform_clinical_catalog_item_audit_logs_actor_idx');
            $table->index('created_at', 'platform_clinical_catalog_item_audit_logs_created_idx');

            $table->foreign('platform_clinical_catalog_item_id', 'platform_clinical_catalog_item_audit_logs_item_fk')
                ->references('id')
                ->on('platform_clinical_catalog_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_clinical_catalog_item_audit_logs');
    }
};
