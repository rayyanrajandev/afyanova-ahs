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
        Schema::create('platform_user_approval_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->unsignedBigInteger('requester_user_id')->nullable();
            $table->unsignedBigInteger('reviewer_user_id')->nullable();
            $table->string('case_reference', 120);
            $table->string('action_type', 40);
            $table->json('action_payload')->nullable();
            $table->string('status', 30)->default('draft');
            $table->text('decision_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'case_reference']);
            $table->index(['tenant_id', 'status']);
            $table->index(['target_user_id', 'status']);
            $table->index(['action_type', 'status']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('target_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('requester_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('reviewer_user_id')
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
        Schema::dropIfExists('platform_user_approval_cases');
    }
};

