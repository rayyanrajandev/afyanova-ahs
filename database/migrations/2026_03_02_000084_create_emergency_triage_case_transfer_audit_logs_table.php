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
        Schema::create('emergency_triage_case_transfer_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('emergency_triage_case_transfer_id');
            $table->uuid('emergency_triage_case_id');
            $table->string('action');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['emergency_triage_case_transfer_id', 'created_at'], 'em_triage_transfer_audit_transfer_created_idx');
            $table->index(['emergency_triage_case_id', 'created_at'], 'em_triage_transfer_audit_case_created_idx');
            $table->index(['action', 'created_at'], 'em_triage_transfer_audit_action_created_idx');
            $table->index(['actor_id', 'created_at'], 'em_triage_transfer_audit_actor_created_idx');

            $table->foreign('emergency_triage_case_transfer_id', 'em_triage_transfer_audit_transfer_fk')
                ->references('id')
                ->on('emergency_triage_case_transfers')
                ->cascadeOnDelete();

            $table->foreign('emergency_triage_case_id', 'em_triage_transfer_audit_case_fk')
                ->references('id')
                ->on('emergency_triage_cases')
                ->cascadeOnDelete();

            $table->foreign('actor_id', 'em_triage_transfer_audit_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emergency_triage_case_transfer_audit_logs');
    }
};
