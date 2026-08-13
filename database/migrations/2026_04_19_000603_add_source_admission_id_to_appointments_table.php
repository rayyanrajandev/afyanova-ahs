<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->uuid('source_admission_id')->nullable()->after('patient_id');
            $table->index('source_admission_id');

            $table->foreign('source_admission_id')
                ->references('id')
                ->on('admissions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['source_admission_id']);
            $table->dropIndex(['source_admission_id']);
            $table->dropColumn('source_admission_id');
        });
    }
};
