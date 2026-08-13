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
        Schema::create('platform_multi_facility_rollout_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('rollout_plan_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 120);
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['rollout_plan_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('rollout_plan_id')
                ->references('id')
                ->on('facility_rollout_plans')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_multi_facility_rollout_audit_logs');
    }
};
