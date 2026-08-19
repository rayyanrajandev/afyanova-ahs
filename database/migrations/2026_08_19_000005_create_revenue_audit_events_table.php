<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit of every financial write.
 *
 * Separate from the clinical audit logs on purpose: money has a different
 * retention expectation, a different reader (a finance controller or an
 * external auditor, not a clinician), and a different question — not "what
 * changed" but "who took this, under whose authority, at which drawer".
 *
 * Nothing updates or deletes rows here. The application exposes no such path,
 * and corrections are recorded as further events, so the log reads as a
 * narrative rather than a current state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();

            // 'service_charge' | 'payment' | 'receipt' | 'refund' | 'cashier_session'
            $table->string('entity_type', 40);
            $table->uuid('entity_id');

            // 'raised' | 'authorized' | 'cancelled' | 'waived' | 'reversed' ...
            $table->string('action', 60);

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_role_code', 60)->nullable();
            $table->uuid('cashier_session_id')->nullable();
            $table->string('ip_address', 45)->nullable();

            // Amount in play, denormalised so a reviewer can scan the log
            // without joining back to a document that may since have been
            // reversed.
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency_code', 3)->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'revenue_audit_entity_idx');
            $table->index(['facility_id', 'occurred_at'], 'revenue_audit_facility_time_idx');
            $table->index(['actor_user_id', 'occurred_at'], 'revenue_audit_actor_time_idx');
            $table->index('cashier_session_id', 'revenue_audit_session_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_audit_events');
    }
};
