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
        Schema::create('inpatient_ward_discharge_checklists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('admission_id')->unique();
            $table->uuid('patient_id');
            $table->string('status', 30)->default('draft');
            $table->string('status_reason')->nullable();
            $table->boolean('clinical_summary_completed')->default(false);
            $table->boolean('medication_reconciliation_completed')->default(false);
            $table->boolean('follow_up_plan_completed')->default(false);
            $table->boolean('patient_education_completed')->default(false);
            $table->boolean('transport_arranged')->default(false);
            $table->boolean('billing_cleared')->default(false);
            $table->boolean('documentation_completed')->default(false);
            $table->boolean('is_ready_for_discharge')->default(false);
            $table->unsignedBigInteger('last_reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'updated_at']);
            $table->index(['facility_id', 'updated_at']);
            $table->index(['status', 'updated_at']);
            $table->index(['patient_id', 'status']);

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

            $table->foreign('last_reviewed_by_user_id')
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
        Schema::dropIfExists('inpatient_ward_discharge_checklists');
    }
};

