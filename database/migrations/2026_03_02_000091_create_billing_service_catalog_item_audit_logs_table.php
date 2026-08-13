<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_service_catalog_item_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('billing_service_catalog_item_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['billing_service_catalog_item_id', 'created_at'], 'billing_service_catalog_item_audit_logs_item_created_at_idx');
            $table->index(['action', 'created_at'], 'billing_service_catalog_item_audit_logs_action_created_at_idx');

            $table->foreign('billing_service_catalog_item_id')
                ->references('id')
                ->on('billing_service_catalog_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_service_catalog_item_audit_logs');
    }
};
