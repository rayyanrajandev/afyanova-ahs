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
        Schema::create('pharmacy_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('order_number')->unique();
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->foreignId('ordered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ordered_at');
            $table->string('medication_code', 100);
            $table->string('medication_name');
            $table->string('dosage_instruction', 1000);
            $table->decimal('quantity_prescribed', 12, 2);
            $table->decimal('quantity_dispensed', 12, 2)->default(0);
            $table->text('dispensing_notes')->nullable();
            $table->timestamp('dispensed_at')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'ordered_at']);
            $table->index(['status', 'ordered_at']);
            $table->index('medication_code');

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('admission_id')->references('id')->on('admissions')->nullOnDelete();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pharmacy_orders');
    }
};
