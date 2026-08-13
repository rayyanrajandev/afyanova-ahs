<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            // appointment_type classifies how the patient arrived.
            // 'scheduled' = standard booked appointment (default, backfilled).
            // 'walk_in'   = unscheduled same-day presentation at the facility.
            // 'referral'  = appointment created as the downstream leg of a formal referral.
            $table->string('appointment_type', 20)
                ->default('scheduled')
                ->after('notes');

            // triage_category records the clinical acuity assigned by the triage nurse
            // using the Manchester Triage System (MTS) P1–P5 scale.
            // NULL = not yet triaged.
            // P1 Resuscitation | P2 Emergent | P3 Urgent | P4 Semi-urgent | P5 Non-urgent
            $table->string('triage_category', 5)
                ->nullable()
                ->after('triage_notes');
        });

        // Backfill: appointments linked from appointment_referrals get type 'referral'.
        DB::table('appointments')
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('appointment_referrals')
                    ->whereColumn('appointment_referrals.appointment_id', 'appointments.id');
            })
            ->update(['appointment_type' => 'referral']);

        // All others remain 'scheduled' (the column default).
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn(['appointment_type', 'triage_category']);
        });
    }
};
