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
        Schema::create('facility_rollout_checkpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('rollout_plan_id');
            $table->string('checkpoint_code', 80);
            $table->string('checkpoint_name', 180);
            $table->string('status', 30)->default('not_started');
            $table->text('decision_notes')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['rollout_plan_id', 'checkpoint_code']);
            $table->index(['rollout_plan_id', 'status']);

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
        Schema::dropIfExists('facility_rollout_checkpoints');
    }
};
