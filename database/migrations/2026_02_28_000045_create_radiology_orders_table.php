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
        Schema::create('radiology_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('order_number')->unique();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->foreignId('ordered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ordered_at');
            $table->string('modality', 30);
            $table->string('study_description', 255);
            $table->text('clinical_indication')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->text('report_summary')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 30)->default('ordered');
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'ordered_at']);
            $table->index(['facility_id', 'ordered_at']);
            $table->index(['patient_id', 'ordered_at']);
            $table->index(['status', 'ordered_at']);
            $table->index('modality');

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
        Schema::dropIfExists('radiology_orders');
    }
};
