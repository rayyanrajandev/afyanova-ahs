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
        Schema::create('admissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('admission_number')->unique();
            $table->uuid('patient_id');
            $table->uuid('appointment_id')->nullable();
            $table->foreignId('attending_clinician_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ward')->nullable();
            $table->string('bed', 50)->nullable();
            $table->timestamp('admitted_at');
            $table->timestamp('discharged_at')->nullable();
            $table->string('admission_reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('admitted');
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'admitted_at']);
            $table->index(['status', 'admitted_at']);

            $table->foreign('patient_id')
                ->references('id')
                ->on('patients')
                ->cascadeOnDelete();

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
        Schema::dropIfExists('admissions');
    }
};
