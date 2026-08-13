<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_order_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('session_number')->unique();
            $table->string('module', 40);
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->foreignId('ordered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->unsignedInteger('item_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['module', 'submitted_at']);
            $table->index(['patient_id', 'submitted_at']);
            $table->index(['appointment_id', 'submitted_at']);
            $table->index(['admission_id', 'submitted_at']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->nullOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('admission_id')->references('id')->on('admissions')->nullOnDelete();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_order_sessions');
    }
};
