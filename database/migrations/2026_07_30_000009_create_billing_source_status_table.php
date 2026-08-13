<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * billing-financial-state-remediation-plan.md, Phase 2. A denormalized read
 * projection -- Billing (via SyncBillingSourceStatusProjection, listening for
 * InvoiceStatusChanged/InvoicePaymentRecorded/InvoicePaymentReversed) is the only
 * writer. One row per known clinical source, upserted whenever its invoice's
 * status changes, so a future consumer (Phase 3's shared resolver) can answer
 * "is this billed" from an indexed read instead of re-deriving it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_source_status', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_workflow_kind', 60);
            $table->string('source_workflow_id', 100);
            $table->string('status', 30);
            $table->uuid('billing_invoice_id')->nullable();
            $table->timestamps();

            $table->unique(['source_workflow_kind', 'source_workflow_id'], 'billing_source_status_source_unique');
            $table->index('billing_invoice_id', 'billing_source_status_invoice_idx');

            $table->foreign('billing_invoice_id')
                ->references('id')
                ->on('billing_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_source_status');
    }
};
