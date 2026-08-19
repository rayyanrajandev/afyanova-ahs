<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gapless numbering for financial documents.
 *
 * The clinical modules generate identifiers as PREFIX + Ymd + Str::random(6),
 * retrying on collision. That is right for an order — the number only has to be
 * unique — and wrong for a receipt, where an auditor reads a missing number as
 * a destroyed document. Receipts, payments and refunds therefore draw from a
 * counter held here and incremented under SELECT ... FOR UPDATE inside the
 * issuing transaction, so two cashiers at two counters cannot take the same
 * number and no number is ever skipped.
 *
 * The counter is per facility and per period, because a sequence that resets
 * annually is what tax authorities and auditors expect to see, and because two
 * facilities in one tenant must not share a receipt book.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_document_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();

            // 'receipt' | 'payment' | 'refund' | 'service_charge' | 'cashier_session'
            $table->string('document_type', 40);

            // The reset window: '2026' for annual, '202608' for monthly, or
            // 'all-time' for a counter that never resets. Part of the key
            // rather than inferred, so changing the policy later cannot
            // silently renumber an existing book.
            $table->string('period_key', 20);

            $table->string('prefix', 20);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(
                ['facility_id', 'document_type', 'period_key'],
                'financial_document_sequences_book_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_document_sequences');
    }
};
