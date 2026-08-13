<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('encounter_number')->unique();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('patient_id');
            $table->uuid('appointment_id')->nullable();
            $table->uuid('admission_id')->nullable();
            $table->foreignId('primary_clinician_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40)->default('opened');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'opened_at']);
            $table->index(['facility_id', 'opened_at']);
            $table->index(['patient_id', 'opened_at']);
            $table->index(['appointment_id', 'status']);
            $table->index(['admission_id', 'status']);
            $table->index(['status', 'opened_at']);

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

            $table->foreign('appointment_id')
                ->references('id')
                ->on('appointments')
                ->nullOnDelete();

            $table->foreign('admission_id')
                ->references('id')
                ->on('admissions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounters');
    }
};
