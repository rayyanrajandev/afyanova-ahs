<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * billing-financial-state-remediation-plan.md, Phase 1. Replaces two independent,
 * unindexed "does an invoice already cover this clinical source" implementations
 * (EloquentBillingInvoiceRepository::findByLineItemSource()'s per-invoice JSON scan,
 * and ListBillingChargeCaptureCandidatesUseCase::invoicedSourceIndex()'s JSON scan +
 * notes-regex fallback) with one indexed table, kept in sync by the repository
 * whenever an invoice's line_items are written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoice_line_item_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('billing_invoice_id');
            $table->string('source_workflow_kind', 60);
            $table->string('source_workflow_id', 100);
            $table->unsignedInteger('line_item_index')->nullable();
            $table->timestamps();

            $table->index(['source_workflow_kind', 'source_workflow_id'], 'billing_invoice_line_item_sources_source_idx');
            $table->index('billing_invoice_id', 'billing_invoice_line_item_sources_invoice_idx');

            $table->foreign('billing_invoice_id')
                ->references('id')
                ->on('billing_invoices')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_line_item_sources');
    }
};
