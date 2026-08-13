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
        Schema::create('emergency_triage_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('case_number')->unique();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->foreignId('assigned_clinician_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('arrived_at');
            $table->string('triage_level', 20);
            $table->string('chief_complaint', 255);
            $table->text('vitals_summary')->nullable();
            $table->timestamp('triaged_at')->nullable();
            $table->text('disposition_notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 30)->default('waiting');
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'arrived_at']);
            $table->index(['facility_id', 'arrived_at']);
            $table->index(['patient_id', 'arrived_at']);
            $table->index(['status', 'arrived_at']);
            $table->index('triage_level');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('patient_id')
                ->references('id')
                ->on('patients')
                ->cascadeOnDelete();

            $table->foreign('admission_id')
                ->references('id')
                ->on('admissions')
                ->nullOnDelete();

            $table->foreign('appointment_id')
                ->references('id')
                ->on('appointments')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emergency_triage_cases');
    }
};
