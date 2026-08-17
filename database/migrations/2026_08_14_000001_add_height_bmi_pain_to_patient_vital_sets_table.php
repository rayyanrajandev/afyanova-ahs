<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_vital_sets', function (Blueprint $table): void {
            $table->decimal('height_cm', 5, 2)->nullable()->after('weight_kg');
            $table->decimal('bmi', 5, 2)->nullable()->after('height_cm');
            $table->unsignedTinyInteger('pain_score')->nullable()->after('bmi');
        });
    }

    public function down(): void
    {
        Schema::table('patient_vital_sets', function (Blueprint $table): void {
            $table->dropColumn(['height_cm', 'bmi', 'pain_score']);
        });
    }
};
