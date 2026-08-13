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
        Schema::create('staff_profile_specialty', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('staff_profile_id');
            $table->uuid('specialty_id');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['staff_profile_id', 'specialty_id']);
            $table->index(['staff_profile_id', 'is_primary']);
            $table->index(['specialty_id', 'is_primary']);

            $table->foreign('staff_profile_id')
                ->references('id')
                ->on('staff_profiles')
                ->cascadeOnDelete();

            $table->foreign('specialty_id')
                ->references('id')
                ->on('clinical_specialties')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_profile_specialty');
    }
};

