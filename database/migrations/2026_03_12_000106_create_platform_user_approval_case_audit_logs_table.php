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
        Schema::create('platform_user_approval_case_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('approval_case_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 120);
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['approval_case_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('approval_case_id')
                ->references('id')
                ->on('platform_user_approval_cases')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_user_approval_case_audit_logs');
    }
};

