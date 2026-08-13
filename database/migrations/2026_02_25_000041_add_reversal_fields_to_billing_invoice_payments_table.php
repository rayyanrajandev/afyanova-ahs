<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoice_payments', function (Blueprint $table): void {
            $table->string('entry_type', 20)->default('payment')->after('cumulative_paid_amount');
            $table->uuid('reversal_of_payment_id')->nullable()->after('entry_type');
            $table->string('reversal_reason', 255)->nullable()->after('reversal_of_payment_id');
            $table->string('approval_case_reference', 120)->nullable()->after('reversal_reason');

            $table->index(['billing_invoice_id', 'entry_type'], 'billing_invoice_payments_invoice_entry_type_idx');
            $table->index(['reversal_of_payment_id'], 'billing_invoice_payments_reversal_of_idx');

            $table->foreign('reversal_of_payment_id', 'billing_invoice_payments_reversal_of_fk')
                ->references('id')
                ->on('billing_invoice_payments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoice_payments', function (Blueprint $table): void {
            $table->dropForeign('billing_invoice_payments_reversal_of_fk');
            $table->dropIndex('billing_invoice_payments_invoice_entry_type_idx');
            $table->dropIndex('billing_invoice_payments_reversal_of_idx');
            $table->dropColumn([
                'entry_type',
                'reversal_of_payment_id',
                'reversal_reason',
                'approval_case_reference',
            ]);
        });
    }
};

