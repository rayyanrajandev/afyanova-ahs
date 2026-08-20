<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anomalies on Revenue's fail-open paths.
 *
 * Deliberately not folded into revenue_audit_events. That table is the audit of
 * financial writes that *happened* — it requires an entity_id, and its whole
 * subject is "who took this, under whose authority, at which drawer". The rows
 * here are the opposite: a charge that was never raised has no entity to point
 * at, and recording non-events in the audit log would corrupt the narrative it
 * exists to tell.
 *
 * Rows are diagnostic, not financial. They are safe to prune on a retention
 * schedule, which is another reason to keep them out of the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_telemetry_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();

            // RevenueTelemetryEvent
            $table->string('event_type', 40);
            // RevenueTelemetryReason
            $table->string('reason', 40)->nullable();
            // ChargeSourceKind, when the anomaly belongs to one
            $table->string('source_kind', 40)->nullable();

            // The appointment or order the anomaly is about. Not a foreign key:
            // it spans five different workflow tables, and a diagnostic row must
            // survive the deletion of whatever it describes.
            $table->uuid('source_workflow_id')->nullable();
            $table->uuid('service_charge_id')->nullable();
            $table->uuid('patient_id')->nullable();

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['event_type', 'occurred_at'], 'revenue_telemetry_type_time_idx');
            $table->index(['facility_id', 'occurred_at'], 'revenue_telemetry_facility_time_idx');
            $table->index(['source_kind', 'reason'], 'revenue_telemetry_kind_reason_idx');
            $table->index('source_workflow_id', 'revenue_telemetry_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_telemetry_events');
    }
};
