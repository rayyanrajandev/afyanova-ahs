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
        Schema::create('laboratory_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('order_number')->unique();
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->foreignId('ordered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ordered_at');
            $table->string('test_code', 50);
            $table->string('test_name');
            $table->string('priority', 20);
            $table->string('specimen_type')->nullable();
            $table->text('clinical_notes')->nullable();
            $table->text('result_summary')->nullable();
            $table->timestamp('resulted_at')->nullable();
            $table->string('status', 20)->default('ordered');
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'ordered_at']);
            $table->index(['status', 'ordered_at']);
            $table->index('priority');
            $table->index('test_code');

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
        Schema::dropIfExists('laboratory_orders');
    }
};
