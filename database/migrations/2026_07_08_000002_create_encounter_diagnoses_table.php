<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounter_diagnoses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('encounter_id');
            $table->string('diagnosis_code', 20);
            $table->string('diagnosis_description')->nullable();
            $table->string('diagnosis_type', 20)->default('secondary');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->index(['encounter_id', 'diagnosis_type']);

            $table->foreign('encounter_id')
                ->references('id')
                ->on('encounters')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounter_diagnoses');
    }
};
