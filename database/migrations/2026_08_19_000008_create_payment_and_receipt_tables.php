<?php

use App\Modules\Revenue\Domain\ValueObjects\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money in, and the document that proves it.
 *
 * A payment is one tender event at the counter. Allocations attach it to the
 * charges it settles — many-to-many on purpose, because one patient pays for a
 * consultation and two lab tests with a single note, and because a future
 * insurer settlement allocates to those same charges without any of this
 * changing shape.
 *
 * Nothing here is ever updated in place. A reversal is a second, linked
 * payment row; a refund is its own document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('payment_number', 40);

            $table->uuid('patient_id');
            $table->uuid('cashier_session_id')->nullable();

            $table->string('method', 40);
            $table->char('currency_code', 3);

            // What was applied to charges.
            $table->bigInteger('amount_minor');
            // Cash only: what the patient handed over, and what went back.
            $table->bigInteger('tendered_amount_minor')->nullable();
            $table->bigInteger('change_amount_minor')->nullable();
            // Maintained as allocations are written and released, so the
            // "cannot allocate more than was paid" rule is a check constraint
            // rather than a query someone has to remember to run.
            $table->bigInteger('allocated_amount_minor')->default(0);

            $table->string('status', 30)->default(PaymentStatus::RECORDED->value);

            $table->timestamp('received_at');
            $table->unsignedBigInteger('received_by_user_id')->nullable();

            $table->uuid('reversal_of_payment_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by_user_id')->nullable();
            $table->string('reversal_reason', 255)->nullable();

            // For a future gateway: the provider's transaction id.
            $table->string('external_reference', 120)->nullable();

            // The same counter transaction submitted twice — a double-tap, a
            // retried request — must return the first receipt, not take the
            // money again.
            $table->string('idempotency_key', 100);

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['facility_id', 'payment_number'], 'payments_number_unique');
            $table->unique('idempotency_key', 'payments_idempotency_unique');

            $table->index(['patient_id', 'received_at'], 'payments_patient_idx');
            $table->index(['cashier_session_id', 'status'], 'payments_session_idx');
            $table->index(['facility_id', 'received_at'], 'payments_facility_time_idx');

            $table->foreign('cashier_session_id')
                ->references('id')->on('cashier_sessions')->nullOnDelete();
        });

        // The self-reference is added after the table exists: inside the same
        // CREATE, PostgreSQL has no primary key on `payments` yet to point at.
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreign('reversal_of_payment_id')
                ->references('id')->on('payments')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            // A recorded payment may never allocate more than it is worth.
            // A reversal row carries a negative amount and, by construction,
            // no allocations at all — it exists to release them.
            DB::statement(<<<'SQL'
                ALTER TABLE payments
                ADD CONSTRAINT payments_allocation_within_amount CHECK (
                    (
                        amount_minor >= 0
                        AND allocated_amount_minor >= 0
                        AND allocated_amount_minor <= amount_minor
                    )
                    OR (
                        amount_minor < 0
                        AND allocated_amount_minor = 0
                    )
                )
            SQL);

            // Cash handed over can never be less than the amount applied; the
            // difference is the change given back.
            // Cash handed over can never be less than the amount applied; the
            // difference is the change given back. Reversals tender nothing,
            // so the column is null and the check passes trivially.
            DB::statement(<<<'SQL'
                ALTER TABLE payments
                ADD CONSTRAINT payments_tender_covers_amount CHECK (
                    tendered_amount_minor IS NULL
                    OR tendered_amount_minor >= amount_minor
                )
            SQL);
        }

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_id');
            $table->uuid('service_charge_id');
            $table->char('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->timestamps();

            // One allocation row per (payment, charge). A top-up is a new
            // payment, not a second allocation on the same pair.
            $table->unique(['payment_id', 'service_charge_id'], 'payment_allocations_pair_unique');
            $table->index('service_charge_id', 'payment_allocations_charge_idx');

            $table->foreign('payment_id')
                ->references('id')->on('payments')->cascadeOnDelete();
            $table->foreign('service_charge_id')
                ->references('id')->on('service_charges')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE payment_allocations
                ADD CONSTRAINT payment_allocations_positive_amount CHECK (amount_minor > 0)
            SQL);
        }

        Schema::create('receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('receipt_number', 40);

            $table->uuid('payment_id');
            $table->uuid('patient_id');

            $table->char('currency_code', 3);
            $table->bigInteger('total_minor');

            // The lines exactly as printed. Kept as a snapshot rather than
            // re-derived at reprint time: a charge can later be refunded or a
            // tariff superseded, and the paper the patient holds must not
            // change retroactively.
            $table->json('snapshot');

            $table->timestamp('issued_at');
            $table->unsignedBigInteger('issued_by_user_id')->nullable();

            // Tanzania will require fiscalised receipts, but no VFD credentials
            // are provisioned. Payment must never block or stall on it, so this
            // defaults to not_required and a later phase backfills
            // asynchronously.
            $table->string('fiscal_status', 30)->default('not_required');
            $table->string('fiscal_reference', 120)->nullable();
            $table->timestamp('fiscal_issued_at')->nullable();
            $table->string('fiscal_error', 255)->nullable();

            $table->unsignedInteger('reprint_count')->default(0);
            $table->timestamp('last_reprinted_at')->nullable();

            $table->timestamps();

            $table->unique(['facility_id', 'receipt_number'], 'receipts_number_unique');
            $table->unique('payment_id', 'receipts_payment_unique');
            $table->index(['patient_id', 'issued_at'], 'receipts_patient_idx');

            $table->foreign('payment_id')
                ->references('id')->on('payments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
