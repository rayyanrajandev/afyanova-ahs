<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026_06_25_000004_add_converted_status_to_cash_billing_accounts widened
 * the `status` column from enum(active/settled/suspended) to string(50) to
 * allow 'converted', but never dropped the check constraint Postgres had
 * created for the original enum — so the database still silently rejected
 * 'converted' (ConvertCashBillingToInvoiceUseCase) and 'voided'
 * (VoidCashBillingAccountUseCase) with a raw SQLSTATE[23514] check
 * violation, confirmed live via billing/CashV2.vue's Void account action.
 * This drops the stale constraint and recreates it covering every status
 * value the application actually writes (active, settled, suspended,
 * voided, converted).
 */
return new class extends Migration
{
    public function up(): void
    {
        // This repairs a Postgres-only auto-generated CHECK constraint (see class
        // docblock) — SQLite never created an equivalent constraint for the original
        // enum column, so there is nothing to repair there.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE cash_billing_accounts DROP CONSTRAINT IF EXISTS cash_billing_accounts_status_check');
        DB::statement("ALTER TABLE cash_billing_accounts ADD CONSTRAINT cash_billing_accounts_status_check CHECK (status IN ('active', 'settled', 'suspended', 'voided', 'converted'))");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE cash_billing_accounts DROP CONSTRAINT IF EXISTS cash_billing_accounts_status_check');
        DB::statement("ALTER TABLE cash_billing_accounts ADD CONSTRAINT cash_billing_accounts_status_check CHECK (status IN ('active', 'settled', 'suspended'))");
    }
};
