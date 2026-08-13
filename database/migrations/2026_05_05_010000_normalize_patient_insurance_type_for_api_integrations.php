<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('patient_insurance_records')
            || ! Schema::hasColumn('patient_insurance_records', 'insurance_type')) {
            return;
        }

        Schema::table('patient_insurance_records', function (Blueprint $table): void {
            $table->string('insurance_type', 40)->default('insurance')->change();
        });

        DB::table('patient_insurance_records')
            ->where('insurance_type', 'private')
            ->update(['insurance_type' => 'insurance']);

        DB::table('patient_insurance_records')
            ->where('insurance_type', 'nhif')
            ->update(['insurance_type' => 'government']);

        DB::table('patient_insurance_records')
            ->where('insurance_type', 'none')
            ->update(['insurance_type' => 'other']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('patient_insurance_records')
            || ! Schema::hasColumn('patient_insurance_records', 'insurance_type')) {
            return;
        }

        DB::table('patient_insurance_records')
            ->where('insurance_type', 'insurance')
            ->update(['insurance_type' => 'private']);

        DB::table('patient_insurance_records')
            ->where('insurance_type', 'government')
            ->update(['insurance_type' => 'nhif']);

        DB::table('patient_insurance_records')
            ->whereIn('insurance_type', ['employer', 'donor'])
            ->update(['insurance_type' => 'other']);

        Schema::table('patient_insurance_records', function (Blueprint $table): void {
            $table->enum('insurance_type', ['private', 'nhif', 'other', 'none'])->default('none')->change();
        });
    }
};
