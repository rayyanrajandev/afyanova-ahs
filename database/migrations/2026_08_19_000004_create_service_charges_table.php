<?php

use App\Modules\Revenue\Domain\ValueObjects\PayerClass;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The unit of financial control in the prepaid model: one row per billable
 * service instance.
 *
 * An invoice answers "has this bill been settled?". Prepaid asks a different
 * question, at a different moment, of a different person — "is *this service*
 * cleared to be delivered?", asked by a lab tech about one test and by
 * reception about one consultation. Keying a charge by (source kind, source id)
 * makes that a single indexed lookup instead of an invoice traversal.
 *
 * Money is stored as integer minor units, never as a float or a decimal the
 * application reads back as one. See App\Modules\Revenue\Domain\ValueObjects\Money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_charges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('charge_number', 40);

            $table->uuid('patient_id');
            $table->uuid('encounter_id')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->uuid('admission_id')->nullable();

            // What clinical thing this pays for.
            $table->string('source_workflow_kind', 60);
            $table->string('source_workflow_id', 100)->nullable();

            // What was priced, and the exact tariff row used. Snapshotting the
            // price_book_entry_id means the tariff behind a historical charge
            // stays recoverable even after the price book is superseded.
            $table->uuid('chargeable_item_id')->nullable();
            $table->uuid('price_book_entry_id')->nullable();
            $table->string('description', 255);
            $table->string('unit', 40)->nullable();
            $table->decimal('quantity', 12, 3)->default(1);

            $table->char('currency_code', 3);
            $table->bigInteger('unit_price_minor')->default(0);
            $table->bigInteger('gross_amount_minor')->default(0);
            $table->bigInteger('discount_amount_minor')->default(0);
            $table->string('discount_reason', 255)->nullable();
            $table->bigInteger('tax_amount_minor')->default(0);
            $table->bigInteger('net_amount_minor')->default(0);

            // Who is expected to pay. Always self_pay / null in this phase;
            // the split is what a future insurer fills in without a migration.
            $table->string('payer_class', 40)->default(PayerClass::SELF_PAY->value);
            $table->uuid('payer_contract_id')->nullable();
            $table->bigInteger('patient_responsibility_minor')->default(0);
            $table->bigInteger('payer_responsibility_minor')->default(0);

            // Maintained by allocation writes in Phase 4; the authorization
            // policy compares it against patient_responsibility_minor.
            $table->bigInteger('allocated_amount_minor')->default(0);

            $table->string('status', 30)->default(ServiceChargeStatus::PENDING_PAYMENT->value);
            $table->string('pricing_status', 40)->nullable();

            $table->string('authorization_basis', 40)->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->unsignedBigInteger('authorized_by_user_id')->nullable();
            $table->string('authorization_reference', 120)->nullable();

            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->string('cancellation_reason', 255)->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['facility_id', 'charge_number'], 'service_charges_number_unique');

            // The cashier queue and the "does this patient owe anything" reader.
            $table->index(['patient_id', 'status'], 'service_charges_patient_status_idx');
            $table->index(['facility_id', 'status'], 'service_charges_facility_status_idx');
            $table->index(['encounter_id'], 'service_charges_encounter_idx');
            $table->index(['appointment_id'], 'service_charges_appointment_idx');
            $table->index(['admission_id'], 'service_charges_admission_idx');

            // The gate's lookup: "is this order cleared?"
            $table->index(
                ['source_workflow_kind', 'source_workflow_id'],
                'service_charges_source_idx',
            );

            $table->foreign('chargeable_item_id')->references('id')->on('chargeable_items')->nullOnDelete();
            $table->foreign('price_book_entry_id')->references('id')->on('price_book_entries')->nullOnDelete();
            $table->foreign('payer_contract_id')->references('id')->on('billing_payer_contracts')->nullOnDelete();
        });

        // One live charge per clinical order. A cancelled charge is excluded so
        // a mistakenly-raised charge can be withdrawn and the order re-charged;
        // anything else would let the same lab test be billed twice.
        //
        // Expressed as a partial index because that is the only form that
        // enforces it in the database rather than in whichever use case
        // happens to remember.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX service_charges_live_source_unique
                ON service_charges (source_workflow_kind, source_workflow_id)
                WHERE source_workflow_id IS NOT NULL
                  AND status <> 'cancelled'
            SQL);
        }

        // Money is never negative on a charge. A correction is a refund row,
        // not a negative charge — see ServiceChargeStatus.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE service_charges
                ADD CONSTRAINT service_charges_non_negative_amounts CHECK (
                    gross_amount_minor >= 0
                    AND discount_amount_minor >= 0
                    AND tax_amount_minor >= 0
                    AND net_amount_minor >= 0
                    AND patient_responsibility_minor >= 0
                    AND payer_responsibility_minor >= 0
                    AND allocated_amount_minor >= 0
                )
            SQL);

            // Allocations can never exceed what the patient actually owes.
            // Overpayment at the counter becomes change, not an over-allocated
            // charge; this is the invariant that keeps a day's takings
            // reconcilable.
            DB::statement(<<<'SQL'
                ALTER TABLE service_charges
                ADD CONSTRAINT service_charges_allocation_within_responsibility CHECK (
                    allocated_amount_minor <= patient_responsibility_minor
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_charges');
    }
};
