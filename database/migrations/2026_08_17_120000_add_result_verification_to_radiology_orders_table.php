<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a radiology report a releasable state.
 *
 * radiology_orders carried only `report_summary`, so `completed` meant both
 * "the radiographer has typed a report" and "the report is on the patient's
 * chart" — indistinguishable. That is the exact defect that made the laboratory
 * workspace unsafe: with no way to tell a draft from a released result, a report
 * reached the clinician the instant it was saved, unreviewed.
 *
 * Mirrors add_result_verification_to_laboratory_orders_table so both modules
 * express the same idea with the same column names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radiology_orders', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable()->after('completed_at');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->text('verification_note')->nullable()->after('verified_by_user_id');
            $table->index('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('radiology_orders', function (Blueprint $table): void {
            $table->dropIndex(['verified_at']);
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn(['verified_at', 'verification_note']);
        });
    }
};
