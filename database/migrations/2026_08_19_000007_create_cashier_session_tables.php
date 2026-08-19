<?php

use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The drawer.
 *
 * Cash is the only tender with physical custody, which makes the session — not
 * the payment — the real control object: money is counted against a shift, and
 * a variance belongs to a named person for a named period.
 *
 * The close is deliberately blind. `declared_cash_minor` is what the cashier
 * counted; `expected_cash_minor` is computed only once that has been submitted.
 * A close screen that shows the expected figure first is not a control, it is
 * a prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('session_number', 40);

            $table->unsignedBigInteger('cashier_user_id');
            $table->char('currency_code', 3);

            $table->timestamp('opened_at');
            $table->unsignedBigInteger('opened_by_user_id')->nullable();
            $table->bigInteger('opening_float_minor')->default(0);

            $table->string('status', 30)->default(CashierSessionStatus::OPEN->value);

            // Written at close, in this order: the cashier's count first, the
            // system's expectation second.
            $table->bigInteger('declared_cash_minor')->nullable();
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->timestamp('counted_at')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by_user_id')->nullable();

            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_note', 255)->nullable();

            $table->timestamps();

            $table->unique(['facility_id', 'session_number'], 'cashier_sessions_number_unique');
            $table->index(['facility_id', 'status'], 'cashier_sessions_facility_status_idx');
            $table->index(['cashier_user_id', 'opened_at'], 'cashier_sessions_cashier_idx');
        });

        // One open drawer per cashier. Two open sessions would make "what is in
        // your till" unanswerable, and is the state a crashed close would
        // otherwise leave behind.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX cashier_sessions_one_open_per_cashier
                ON cashier_sessions (cashier_user_id)
                WHERE status = 'open'
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE cashier_sessions
                ADD CONSTRAINT cashier_sessions_non_negative_float CHECK (
                    opening_float_minor >= 0
                    AND (declared_cash_minor IS NULL OR declared_cash_minor >= 0)
                )
            SQL);
        }

        Schema::create('cashier_session_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('cashier_session_id');

            // 'in' | 'out' — derived from the reason, stored so the running
            // total is a plain sum rather than a case expression.
            $table->string('direction', 8);
            $table->string('reason', 40);
            $table->char('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->string('note', 255)->nullable();

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('cashier_session_id', 'cashier_session_movements_session_idx');

            $table->foreign('cashier_session_id')
                ->references('id')->on('cashier_sessions')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE cashier_session_movements
                ADD CONSTRAINT cashier_session_movements_positive_amount CHECK (amount_minor > 0)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_session_movements');
        Schema::dropIfExists('cashier_sessions');
    }
};
