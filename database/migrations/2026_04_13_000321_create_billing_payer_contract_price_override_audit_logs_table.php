<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payer_contract_price_override_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('billing_payer_contract_price_override_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 120);
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');

            $table->index(['billing_payer_contract_price_override_id', 'created_at'], 'billing_payer_contract_price_override_audit_logs_override_created_at_idx');
            $table->index(['action', 'created_at'], 'billing_payer_contract_price_override_audit_logs_action_created_at_idx');

            $table->foreign('billing_payer_contract_price_override_id')
                ->references('id')
                ->on('billing_payer_contract_price_overrides')
                ->cascadeOnDelete();

            $table->foreign('actor_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payer_contract_price_override_audit_logs');
    }
};
