<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_referrals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('appointment_id');
            $table->string('referral_number', 30)->unique();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('facility_id')->nullable();
            $table->string('referral_type', 20);
            $table->string('priority', 20);
            $table->string('target_department', 120)->nullable();
            $table->string('target_facility_name', 180)->nullable();
            $table->unsignedBigInteger('target_clinician_user_id')->nullable();
            $table->string('referral_reason', 255)->nullable();
            $table->text('clinical_notes')->nullable();
            $table->text('handoff_notes')->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('handed_off_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('status', 30);
            $table->string('status_reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('appointment_id')->references('id')->on('appointments')->cascadeOnDelete();
            $table->foreign('target_clinician_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['appointment_id', 'status']);
            $table->index(['requested_at']);
            $table->index(['tenant_id', 'facility_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_referrals');
    }
};

