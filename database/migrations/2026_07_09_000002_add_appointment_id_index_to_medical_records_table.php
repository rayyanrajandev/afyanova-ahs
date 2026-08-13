<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the batched hasSignedConsultationNoteForAppointments() lookup added
 * for clinician/Queue.vue's "note signed" indicator
 * (reports/appointments-scheduling-workspace-modernization-plan.md).
 * appointment_id had a foreign key (2026_02_25_000008_create_medical_records_table.php)
 * but no index — Postgres does not implicitly index foreign key columns
 * (unlike MySQL/InnoDB), so every hasSignedConsultationNoteForAppointment()/
 * hasDraftConsultationNoteForAppointment() lookup (single-row, already used
 * by AppointmentController::updateProviderWorkflow()'s completion gate) was
 * an unindexed scan. Composite with record_type/status since every existing
 * caller filters on all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->index(['appointment_id', 'record_type', 'status'], 'medical_records_appointment_record_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropIndex('medical_records_appointment_record_status_idx');
        });
    }
};
