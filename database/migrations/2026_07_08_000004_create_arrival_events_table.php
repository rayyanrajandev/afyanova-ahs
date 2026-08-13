<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of reports/patient-arrival-checkin-modernization-plan.md: makes patient
 * arrival a first-class, auditable event instead of only an appointment-status
 * side effect (reports/patient-arrival-checkin-audit.md §3, §6 — "checked in" was
 * previously just status=waiting_triage + a checked_in_at timestamp, with no
 * record of arrival mode or who recorded it). Additive only — the existing
 * status/checked_in_at columns and semantics are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arrival_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('appointment_id');
            $table->string('arrival_mode', 40);
            $table->timestamp('arrived_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verification_notes', 2000)->nullable();
            $table->timestamps();

            $table->index(['appointment_id', 'arrived_at']);
            $table->index(['tenant_id', 'arrived_at']);
            $table->index(['facility_id', 'arrived_at']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('appointment_id')
                ->references('id')
                ->on('appointments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arrival_events');
    }
};
