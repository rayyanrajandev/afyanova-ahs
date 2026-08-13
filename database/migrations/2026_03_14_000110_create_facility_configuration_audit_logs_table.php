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
        Schema::create('facility_configuration_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('facility_id');
            $table->foreignId('actor_id')->nullable();
            $table->string('action', 120);
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('facility_id')->references('id')->on('facilities')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['facility_id', 'created_at'], 'facility_configuration_audit_logs_facility_created_at_idx');
            $table->index(['action', 'created_at'], 'facility_configuration_audit_logs_action_created_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facility_configuration_audit_logs');
    }
};
