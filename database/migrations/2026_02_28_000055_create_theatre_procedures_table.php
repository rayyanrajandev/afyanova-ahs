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
        Schema::create('theatre_procedures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('procedure_number')->unique();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->string('procedure_type', 120);
            $table->string('procedure_name', 180)->nullable();
            $table->unsignedBigInteger('operating_clinician_user_id');
            $table->unsignedBigInteger('anesthetist_user_id')->nullable();
            $table->string('theatre_room_name', 120)->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 30)->default('planned');
            $table->string('status_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'scheduled_at']);
            $table->index(['facility_id', 'scheduled_at']);
            $table->index(['patient_id', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
            $table->index(['operating_clinician_user_id', 'scheduled_at']);

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

            $table->foreign('operating_clinician_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign('anesthetist_user_id')
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
        Schema::dropIfExists('theatre_procedures');
    }
};
