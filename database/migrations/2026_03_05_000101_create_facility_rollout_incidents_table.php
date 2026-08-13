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
        Schema::create('facility_rollout_incidents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('rollout_plan_id');
            $table->string('incident_code', 80);
            $table->string('severity', 20);
            $table->string('status', 20);
            $table->string('summary', 200);
            $table->text('details')->nullable();
            $table->string('escalated_to', 200)->nullable();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['rollout_plan_id', 'incident_code']);
            $table->index(['rollout_plan_id', 'severity', 'status']);
            $table->index(['opened_at', 'resolved_at']);

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
        Schema::dropIfExists('facility_rollout_incidents');
    }
};
