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
        Schema::create('medical_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('record_number')->unique();
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('encounter_at');
            $table->string('record_type');
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->string('diagnosis_code', 50)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'encounter_at']);
            $table->index(['status', 'encounter_at']);
            $table->index('record_type');

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
        Schema::dropIfExists('medical_records');
    }
};
