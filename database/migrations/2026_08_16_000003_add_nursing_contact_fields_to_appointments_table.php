<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "With Nurse" as a real, visible state — the third instance of the ownership
 * shape this codebase already uses twice: consultation_owner_user_id
 * (2026_04_06_000400) and triage_owner_user_id (2026_07_09_000001). A nursing
 * pickup is metadata alongside the existing status, not a status transition,
 * exactly like a triage claim.
 *
 * Why columns and not just the patient_flow_events log (2026_08_16_000001):
 * that log is deliberately best-effort — RecordPatientFlowTransitionService
 * swallows failures inside a savepoint so a broken log can never fail a
 * clinical action. That is the right trade for an audit trail and the wrong
 * one for a queue board: a silently-missed write would make a patient vanish
 * from a queue. Current state therefore lives on the transactional, guarded
 * path (these columns); the log keeps the history of how the patient got here.
 *
 * No backfill: nursing pickup did not exist before this, so every existing
 * visit is correctly un-picked-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            if (! Schema::hasColumn('appointments', 'nursing_contact_user_id')) {
                $table->foreignId('nursing_contact_user_id')
                    ->nullable()
                    ->after('triage_owner_assigned_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('appointments', 'nursing_contact_started_at')) {
                $table->timestamp('nursing_contact_started_at')
                    ->nullable()
                    ->after('nursing_contact_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            if (Schema::hasColumn('appointments', 'nursing_contact_started_at')) {
                $table->dropColumn('nursing_contact_started_at');
            }

            if (Schema::hasColumn('appointments', 'nursing_contact_user_id')) {
                $table->dropConstrainedForeignId('nursing_contact_user_id');
            }
        });
    }
};
