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
        Schema::create('inpatient_ward_care_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('care_plan_number')->unique();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('admission_id');
            $table->uuid('patient_id');
            $table->string('title', 180);
            $table->text('plan_text')->nullable();
            $table->json('goals')->nullable();
            $table->json('interventions')->nullable();
            $table->timestamp('target_discharge_at')->nullable();
            $table->timestamp('review_due_at')->nullable();
            $table->string('status', 30)->default('active');
            $table->string('status_reason')->nullable();
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->unsignedBigInteger('last_updated_by_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['facility_id', 'created_at']);
            $table->index(['admission_id', 'status']);
            $table->index(['patient_id', 'status']);
            $table->index(['status', 'updated_at']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('admission_id')
                ->references('id')
                ->on('admissions')
                ->cascadeOnDelete();

            $table->foreign('patient_id')
                ->references('id')
                ->on('patients')
                ->cascadeOnDelete();

            $table->foreign('author_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('last_updated_by_user_id')
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
        Schema::dropIfExists('inpatient_ward_care_plans');
    }
};

