<?php

use App\Modules\Revenue\Domain\ValueObjects\RefundStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two ways an authorized charge is undone.
 *
 * A refund returns money the facility has already taken. A waiver authorizes a
 * service without money changing hands at all. Both are exceptions to the
 * prepaid rule, both need a second person, and both are recorded as their own
 * document rather than as an edit to something else — the question an auditor
 * asks is never "what is the balance" but "who decided this, and why".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('refund_number', 40);

            $table->uuid('patient_id');
            $table->uuid('original_payment_id');
            $table->uuid('service_charge_id')->nullable();

            $table->char('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->string('reason', 255);

            $table->string('status', 30)->default(RefundStatus::REQUESTED->value);

            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('requested_at');

            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_note', 255)->nullable();

            $table->unsignedBigInteger('rejected_by_user_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            // Money leaves a named drawer, so a refund shows up in that
            // session's expected cash.
            $table->uuid('paid_from_session_id')->nullable();
            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->unique(['facility_id', 'refund_number'], 'refunds_number_unique');
            $table->index(['facility_id', 'status'], 'refunds_facility_status_idx');
            $table->index('original_payment_id', 'refunds_payment_idx');

            $table->foreign('original_payment_id')
                ->references('id')->on('payments')->cascadeOnDelete();
            $table->foreign('service_charge_id')
                ->references('id')->on('service_charges')->nullOnDelete();
            $table->foreign('paid_from_session_id')
                ->references('id')->on('cashier_sessions')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE refunds
                ADD CONSTRAINT refunds_positive_amount CHECK (amount_minor > 0)
            SQL);

            // Segregation of duties, enforced in the database as well as in the
            // use case: the person who asks for money back cannot be the person
            // who approves it.
            DB::statement(<<<'SQL'
                ALTER TABLE refunds
                ADD CONSTRAINT refunds_requester_is_not_approver CHECK (
                    approved_by_user_id IS NULL
                    OR approved_by_user_id <> requested_by_user_id
                )
            SQL);
        }

        Schema::create('charge_waivers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('service_charge_id');

            // 'waiver' | 'emergency' — the AuthorizationBasis granted.
            $table->string('basis', 40);
            $table->char('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->string('reason', 255);

            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id');
            $table->timestamp('approved_at');

            $table->timestamps();

            $table->index('service_charge_id', 'charge_waivers_charge_idx');
            $table->index(['facility_id', 'approved_at'], 'charge_waivers_facility_time_idx');

            $table->foreign('service_charge_id')
                ->references('id')->on('service_charges')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE charge_waivers
                ADD CONSTRAINT charge_waivers_positive_amount CHECK (amount_minor >= 0)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_waivers');
        Schema::dropIfExists('refunds');
    }
};
