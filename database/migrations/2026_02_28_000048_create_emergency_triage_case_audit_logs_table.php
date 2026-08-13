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
        Schema::create('emergency_triage_case_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('emergency_triage_case_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['emergency_triage_case_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('emergency_triage_case_id')
                ->references('id')
                ->on('emergency_triage_cases')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emergency_triage_case_audit_logs');
    }
};
