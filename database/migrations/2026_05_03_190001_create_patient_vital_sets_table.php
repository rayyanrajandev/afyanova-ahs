<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_vital_sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('patient_id')->index();
            $table->uuid('admission_id')->nullable()->index();
            $table->uuid('appointment_id')->nullable()->index();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->decimal('temperature_c', 5, 2)->nullable();
            $table->smallInteger('heart_rate_bpm')->nullable();
            $table->smallInteger('systolic_bp_mmhg')->nullable();
            $table->smallInteger('diastolic_bp_mmhg')->nullable();
            $table->decimal('oxygen_saturation_pct', 5, 2)->nullable();
            $table->smallInteger('respiratory_rate_bpm')->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->string('entry_state', 20)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_vital_sets');
    }
};
