<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            if (Schema::hasColumn('medical_records', 'theatre_procedure_id')) {
                return;
            }

            $table->uuid('theatre_procedure_id')->nullable()->after('appointment_referral_id');
            $table->foreign('theatre_procedure_id')
                ->references('id')
                ->on('theatre_procedures')
                ->nullOnDelete();
            $table->index('theatre_procedure_id');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('medical_records', 'theatre_procedure_id')) {
                return;
            }

            $table->dropForeign(['theatre_procedure_id']);
            $table->dropIndex(['theatre_procedure_id']);
            $table->dropColumn('theatre_procedure_id');
        });
    }
};
