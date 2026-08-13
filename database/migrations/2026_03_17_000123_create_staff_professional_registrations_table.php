<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_professional_registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('staff_profile_id');
            $table->uuid('tenant_id')->nullable();
            $table->uuid('staff_regulatory_profile_id')->nullable();
            $table->string('regulator_code', 30);
            $table->string('registration_category', 80);
            $table->string('registration_number', 120);
            $table->string('license_number', 120)->nullable();
            $table->string('registration_status', 30);
            $table->string('license_status', 30);
            $table->string('verification_status', 30)->default('pending');
            $table->string('verification_reason', 255)->nullable();
            $table->text('verification_notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->date('renewal_due_at')->nullable();
            $table->date('cpd_cycle_start_at')->nullable();
            $table->date('cpd_cycle_end_at')->nullable();
            $table->unsignedInteger('cpd_points_required')->nullable();
            $table->unsignedInteger('cpd_points_earned')->nullable();
            $table->uuid('source_document_id')->nullable();
            $table->string('source_system', 80)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['staff_profile_id', 'regulator_code']);
            $table->index(['tenant_id', 'regulator_code', 'license_status']);
            $table->index(['expires_at', 'verification_status']);
            $table->unique(
                ['staff_profile_id', 'regulator_code', 'registration_number'],
                'staff_professional_registrations_unique_scope'
            );

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

            $table->foreign('verified_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('source_document_id')
                ->references('id')
                ->on('staff_documents')
                ->nullOnDelete();

            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('updated_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_professional_registrations');
    }
};
