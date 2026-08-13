<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('billing_invoices') || Schema::hasColumn('billing_invoices', 'source_cash_billing_account_id')) {
            return;
        }

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->uuid('source_cash_billing_account_id')->nullable()->after('encounter_id');
            $table->index('source_cash_billing_account_id');
            $table->foreign('source_cash_billing_account_id')
                ->references('id')
                ->on('cash_billing_accounts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('billing_invoices') || ! Schema::hasColumn('billing_invoices', 'source_cash_billing_account_id')) {
            return;
        }

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropForeign(['source_cash_billing_account_id']);
            $table->dropIndex(['source_cash_billing_account_id']);
            $table->dropColumn('source_cash_billing_account_id');
        });
    }
};
