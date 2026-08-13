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
        Schema::create('facility_rollout_acceptance_signoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('rollout_plan_id')->unique();
            $table->timestamp('training_completed_at')->nullable();
            $table->string('acceptance_status', 20)->default('pending');
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acceptance_case_reference', 120)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

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
        Schema::dropIfExists('facility_rollout_acceptance_signoffs');
    }
};
