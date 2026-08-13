<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_allergies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->uuid('tenant_id')->nullable();
            $table->string('substance_code', 100)->nullable();
            $table->string('substance_name', 255);
            $table->string('reaction', 255)->nullable();
            $table->string('severity', 32)->default('unknown');
            $table->string('status', 32)->default('active');
            $table->dateTime('noted_at')->nullable();
            $table->date('last_reaction_at')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status'], 'patient_allergies_patient_status_idx');
            $table->index(['patient_id', 'substance_code'], 'patient_allergies_patient_substance_code_idx');
            $table->index('tenant_id', 'patient_allergies_tenant_id_idx');

            $table->foreign('patient_id')
                ->references('id')
                ->on('patients')
                ->cascadeOnDelete();
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();
        });

        Schema::create('patient_medication_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->uuid('tenant_id')->nullable();
            $table->string('medication_code', 100)->nullable();
            $table->string('medication_name', 255);
            $table->string('dose', 255)->nullable();
            $table->string('route', 100)->nullable();
            $table->string('frequency', 255)->nullable();
            $table->string('source', 32)->default('home_medication');
            $table->string('status', 32)->default('active');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('stopped_at')->nullable();
            $table->string('indication', 255)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->dateTime('last_reconciled_at')->nullable();
            $table->string('reconciliation_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status'], 'patient_med_profiles_patient_status_idx');
            $table->index(['patient_id', 'medication_code'], 'patient_med_profiles_patient_medication_code_idx');
            $table->index('tenant_id', 'patient_med_profiles_tenant_id_idx');

            $table->foreign('patient_id')
                ->references('id')
                ->on('patients')
                ->cascadeOnDelete();
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_medication_profiles');
        Schema::dropIfExists('patient_allergies');
    }
};
