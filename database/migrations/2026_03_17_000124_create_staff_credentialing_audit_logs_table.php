<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_credentialing_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('staff_profile_id');
            $table->uuid('tenant_id')->nullable();
            $table->uuid('staff_regulatory_profile_id')->nullable();
            $table->uuid('staff_professional_registration_id')->nullable();
            $table->string('action', 120);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('changes');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['staff_profile_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('staff_profile_id')
                ->references('id')
                ->on('staff_profiles')
                ->cascadeOnDelete();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('staff_regulatory_profile_id')
                ->references('id')
                ->on('staff_regulatory_profiles')
                ->nullOnDelete();

            $table->foreign('staff_professional_registration_id')
                ->references('id')
                ->on('staff_professional_registrations')
                ->nullOnDelete();

            $table->foreign('actor_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_credentialing_audit_logs');
    }
};
