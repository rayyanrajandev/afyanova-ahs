<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The patient-flow "one truth" log: an append-only, ordered record of every
 * step a patient takes through a visit.
 *
 * Why this exists — reports/queue-based-workflow-audit.md documented that
 * GetActiveVisitJourneyUseCase derives one `step` per visit at read time from
 * appointments.status plus seven side columns, encounters.status,
 * service_requests.status and open lab/pharmacy/radiology orders. That
 * derivation is correct for "where is this patient right now," but it is a
 * projection, not a record: it can reconstruct the present and cannot state
 * the past. That use case says so itself — waiting_clinician and
 * waiting_clinician_review return stepEnteredAt: null, because no column
 * anywhere marks the moment a patient entered those steps.
 *
 * This table is the missing record. Status columns are deliberately NOT
 * removed or replaced: they keep serving the fast indexed queries every queue
 * screen already relies on, and become a cache of the latest event rather
 * than the only evidence a transition happened.
 *
 * Append-only by construction, not just by convention: no updated_at, and
 * PatientFlowEventModel has $timestamps = false with no update path. A
 * correction is a new event, never an edit — same reasoning as every
 * *_audit_logs table in this codebase.
 *
 * Both visit origins are nullable and neither is required alone, because a
 * visit legitimately has one or the other: appointment-based visits carry
 * appointment_id, while direct-service walk-ins (Phase 1b of the board) often
 * have no appointment at all and are identified by service_request_id. The
 * CHECK-equivalent — at least one present — is enforced in
 * RecordPatientFlowTransitionService rather than in the schema, so SQLite
 * (the test driver) and PostgreSQL behave identically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_flow_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('facility_id')->nullable();

            $table->uuid('patient_id');
            $table->uuid('appointment_id')->nullable();
            $table->uuid('service_request_id')->nullable();
            $table->uuid('encounter_id')->nullable();

            // The board's own step vocabulary (PatientFlowStep), not the raw
            // appointments.status enum — a status of `waiting_provider` maps to
            // either waiting_clinician or waiting_clinician_review depending on
            // history, and that distinction is exactly what this log exists to
            // make explicit rather than re-infer.
            $table->string('from_step', 48)->nullable();
            $table->string('to_step', 48);

            $table->unsignedBigInteger('actor_user_id')->nullable();
            // Denormalized on purpose: a staff member's role can change, and the
            // log must keep saying who they were acting as at the time.
            $table->string('actor_role', 64)->nullable();

            // module.action provenance, e.g. `clinician.start_consultation` or
            // `nursing.vitals_recorded` — makes "which code path wrote this"
            // answerable without correlating timestamps across audit tables.
            $table->string('source', 64);

            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at');

            // The per-patient timeline read — the activity log's only query.
            $table->index(['patient_id', 'occurred_at']);
            // The per-visit timeline, and the board's stepEnteredAt lookup.
            $table->index(['appointment_id', 'occurred_at']);
            $table->index(['service_request_id', 'occurred_at']);
            // Facility-wide flow analytics (time-in-step, throughput).
            $table->index(['facility_id', 'to_step', 'occurred_at']);

            $table->foreign('patient_id')
                ->references('id')
                ->on('patients')
                ->cascadeOnDelete();

            $table->foreign('appointment_id')
                ->references('id')
                ->on('appointments')
                ->nullOnDelete();

            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_flow_events');
    }
};
