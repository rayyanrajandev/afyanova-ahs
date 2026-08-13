<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payer_authorization_rule_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('billing_payer_authorization_rule_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['billing_payer_authorization_rule_id', 'created_at'], 'billing_payer_auth_rule_audit_logs_rule_created_at_idx');
            $table->index(['action', 'created_at'], 'billing_payer_auth_rule_audit_logs_action_created_at_idx');

            $table->foreign('billing_payer_authorization_rule_id')
                ->references('id')
                ->on('billing_payer_authorization_rules')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payer_authorization_rule_audit_logs');
    }
};
