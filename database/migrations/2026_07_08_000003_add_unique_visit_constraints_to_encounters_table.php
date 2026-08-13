<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-4 (reports/clinical-note-audit/15-critical-system-integrity-review.md):
 * EncounterResolverService::findOrCreateForVisit() previously had no DB-level
 * constraint backing its check-then-create logic, so two near-simultaneous
 * resolutions for the same appointment or admission could each create their
 * own encounter row for the same visit. A nullable-column unique index
 * (multiple NULLs remain permitted) makes one-encounter-per-visit an actual
 * database guarantee, and turns the race into a catchable unique-constraint
 * violation the resolver can recover from instead of silently duplicating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->unique('appointment_id', 'encounters_appointment_id_unique');
            $table->unique('admission_id', 'encounters_admission_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropUnique('encounters_appointment_id_unique');
            $table->dropUnique('encounters_admission_id_unique');
        });
    }
};
