<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('patient_allergies', function (Blueprint $table): void {
            $table->renameColumn('status', 'clinical_status');
        });

        Schema::table('patient_allergies', function (Blueprint $table): void {
            $table->string('verification_status', 32)->default('unconfirmed')->after('clinical_status');
            $table->string('type', 32)->default('allergy')->after('verification_status');
            $table->string('category', 32)->nullable()->after('type');
            $table->string('reaction_code', 100)->nullable()->after('reaction');
            $table->string('source', 100)->default('unknown')->after('notes');
        });

        DB::table('patient_allergies')->where('clinical_status', 'entered_in_error')->update([
            'verification_status' => 'entered_in_error',
            'clinical_status' => 'inactive',
        ]);
        DB::table('patient_allergies')->where('clinical_status', 'active')->update([
            'verification_status' => 'confirmed',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_allergies', function (Blueprint $table): void {
            $table->dropColumn(['verification_status', 'type', 'category', 'reaction_code', 'source']);
        });

        Schema::table('patient_allergies', function (Blueprint $table): void {
            $table->renameColumn('clinical_status', 'status');
        });
    }
};
