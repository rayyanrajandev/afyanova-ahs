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
        Schema::create('staff_privilege_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('staff_profile_id');
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id');
            $table->uuid('specialty_id');
            $table->string('privilege_code', 60);
            $table->string('privilege_name', 180);
            $table->text('scope_notes')->nullable();
            $table->date('granted_at');
            $table->date('review_due_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('status_reason', 255)->nullable();
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['staff_profile_id', 'created_at']);
            $table->index(['staff_profile_id', 'status']);
            $table->index(['staff_profile_id', 'facility_id']);
            $table->index(['staff_profile_id', 'specialty_id']);
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['review_due_at', 'status']);
            $table->unique(['staff_profile_id', 'facility_id', 'specialty_id', 'privilege_code'], 'staff_privilege_grants_unique_scope');

            $table->foreign('staff_profile_id')
                ->references('id')
                ->on('staff_profiles')
                ->cascadeOnDelete();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->cascadeOnDelete();

            $table->foreign('specialty_id')
                ->references('id')
                ->on('clinical_specialties')
                ->cascadeOnDelete();

            $table->foreign('granted_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('updated_by_user_id')
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
        Schema::dropIfExists('staff_privilege_grants');
    }
};

