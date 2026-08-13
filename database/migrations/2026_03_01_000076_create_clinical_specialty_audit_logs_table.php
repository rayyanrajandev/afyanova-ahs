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
        Schema::create('clinical_specialty_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('specialty_id')->nullable();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('staff_profile_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['specialty_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['staff_profile_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('specialty_id')
                ->references('id')
                ->on('clinical_specialties')
                ->nullOnDelete();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('staff_profile_id')
                ->references('id')
                ->on('staff_profiles')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinical_specialty_audit_logs');
    }
};

