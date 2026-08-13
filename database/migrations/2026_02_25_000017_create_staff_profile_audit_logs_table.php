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
        Schema::create('staff_profile_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('staff_profile_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['staff_profile_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('staff_profile_id')
                ->references('id')
                ->on('staff_profiles')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_profile_audit_logs');
    }
};
