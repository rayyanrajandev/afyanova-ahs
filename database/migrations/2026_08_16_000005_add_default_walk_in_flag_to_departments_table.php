<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the department a walk-in lands in when nobody has said otherwise.
 *
 * Walk-in registration wrote `department => null`, and nothing downstream ever
 * asked for one, so every walk-in reached the provider queue belonging to no
 * clinic — invisible on department-filtered boards and unattributable for
 * department stock consumption (2026-08-16 routing audit).
 *
 * The decision is: a walk-in defaults to general outpatients, and a nurse
 * re-routes at triage if the patient needs a different clinic. That makes
 * routing a *change* rather than a capture step someone must remember, so no
 * visit is ever unrouted.
 *
 * A flag rather than a hardcoded 'OPD' lookup: departments are facility-scoped,
 * and a facility is free to call its general clinic something else or to point
 * new walk-ins somewhere else entirely. Seeded true for OPD because that is
 * what the baseline catalog ships as the general outpatient department.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            if (! Schema::hasColumn('departments', 'is_default_walk_in')) {
                $table->boolean('is_default_walk_in')
                    ->default(false)
                    ->after('is_appointmentable');

                $table->index(['facility_id', 'is_default_walk_in']);
            }
        });

        // Only ever promote a department that is already a valid routing target;
        // a default that is not appointmentable would route walk-ins somewhere
        // the scheduling form itself refuses to offer.
        DB::table('departments')
            ->where('code', 'OPD')
            ->where('status', 'active')
            ->where('is_appointmentable', true)
            ->update(['is_default_walk_in' => true]);
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            if (Schema::hasColumn('departments', 'is_default_walk_in')) {
                $table->dropIndex(['facility_id', 'is_default_walk_in']);
                $table->dropColumn('is_default_walk_in');
            }
        });
    }
};
