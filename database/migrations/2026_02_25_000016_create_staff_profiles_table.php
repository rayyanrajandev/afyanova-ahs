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
        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('employee_number')->unique();
            $table->string('department', 100);
            $table->string('job_title', 150);
            $table->string('professional_license_number', 100)->nullable();
            $table->string('license_type', 100)->nullable();
            $table->string('phone_extension', 20)->nullable();
            $table->string('employment_type', 30);
            $table->string('status', 30)->default('active');
            $table->string('status_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'department']);
            $table->index(['employment_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
