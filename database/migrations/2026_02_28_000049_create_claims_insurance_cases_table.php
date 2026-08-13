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
        Schema::create('claims_insurance_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('claim_number')->unique();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('invoice_id');
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->string('payer_type', 40);
            $table->string('payer_name', 120)->nullable();
            $table->string('payer_reference', 120)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('adjudicated_at')->nullable();
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->decimal('rejected_amount', 12, 2)->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['facility_id', 'created_at']);
            $table->index(['invoice_id', 'created_at']);
            $table->index(['patient_id', 'created_at']);
            $table->index(['status', 'submitted_at']);
            $table->index(['payer_type', 'submitted_at']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('invoice_id')
                ->references('id')
                ->on('billing_invoices')
                ->cascadeOnDelete();

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
        Schema::dropIfExists('claims_insurance_cases');
    }
};
