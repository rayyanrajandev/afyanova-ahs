<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of reports/queue-based-workflow-modernization-plan.md: "In Triage"
 * as a real, visible state, reusing the consultation-ownership shape
 * (consultation_owner_user_id/consultation_owner_assigned_at, added by
 * 2026_04_06_000400) rather than a new AppointmentStatus enum value — a
 * claim is metadata alongside WAITING_TRIAGE, not a status transition.
 * No backfill: triage claiming did not exist before this, so every existing
 * WAITING_TRIAGE appointment is correctly unclaimed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            if (! Schema::hasColumn('appointments', 'triage_owner_user_id')) {
                $table->foreignId('triage_owner_user_id')
                    ->nullable()
                    ->after('triaged_by_user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('appointments', 'triage_owner_assigned_at')) {
                $table->timestamp('triage_owner_assigned_at')
                    ->nullable()
                    ->after('triage_owner_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            if (Schema::hasColumn('appointments', 'triage_owner_assigned_at')) {
                $table->dropColumn('triage_owner_assigned_at');
            }

            if (Schema::hasColumn('appointments', 'triage_owner_user_id')) {
                $table->dropConstrainedForeignId('triage_owner_user_id');
            }
        });
    }
};
