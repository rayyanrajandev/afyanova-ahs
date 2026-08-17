<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gives every existing facility a real Emergency department.
 *
 * `RegisterWalkInAndCheckInUseCase` has always written the literal string
 * 'Emergency' as an emergency arrival's department, and no Emergency department
 * ever existed to point at — zero rows matched (2026-08-16 routing audit). That
 * string is load-bearing rather than decorative:
 * EncounterResolverService::deriveEncounterType() types the encounter by
 * `str_contains(strtolower($department), 'emergency')`.
 *
 * "Emergency Department" therefore still satisfies that match, so encounter
 * typing keeps working unchanged while the department becomes a real, routable
 * row with an id — which is what makes it filterable on boards and
 * attributable for department stock.
 *
 * Seeded here as well as in DskDepartmentsSeeder because the seeder only runs
 * for new environments; existing facilities need it backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('departments') || ! Schema::hasTable('facilities')) {
            return;
        }

        foreach (DB::table('facilities')->select('id', 'tenant_id')->get() as $facility) {
            $exists = DB::table('departments')
                ->where('facility_id', $facility->id)
                ->where('code', 'EMD')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('departments')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $facility->tenant_id,
                'facility_id' => $facility->id,
                'code' => 'EMD',
                'name' => 'Emergency Department',
                'service_type' => 'Emergency',
                'description' => 'Receives emergency arrivals for immediate assessment and stabilisation. Handles walk-in emergencies, resuscitation, and escalation from triage when a patient deteriorates.',
                'status' => 'active',
                'is_patient_facing' => true,
                'is_appointmentable' => true,
                'is_default_walk_in' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('departments')) {
            return;
        }

        // Only remove rows nothing has been routed to, so reversing this cannot
        // orphan a live visit's department_id.
        DB::table('departments')
            ->where('code', 'EMD')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('appointments')
                    ->whereColumn('appointments.department_id', 'departments.id');
            })
            ->delete();
    }
};
