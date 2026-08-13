<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->uuid('billing_payer_contract_id')->nullable()->after('appointment_id');
            $table->string('pricing_mode', 40)->default('manual')->after('line_items');
            $table->jsonb('pricing_context')->nullable()->after('pricing_mode');

            $table->index(['billing_payer_contract_id', 'invoice_date'], 'billing_invoices_payer_contract_invoice_date_idx');
            $table->index(['pricing_mode', 'invoice_date'], 'billing_invoices_pricing_mode_invoice_date_idx');

            $table->foreign('billing_payer_contract_id')
                ->references('id')
                ->on('billing_payer_contracts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropForeign(['billing_payer_contract_id']);
            $table->dropIndex('billing_invoices_payer_contract_invoice_date_idx');
            $table->dropIndex('billing_invoices_pricing_mode_invoice_date_idx');
            $table->dropColumn([
                'billing_payer_contract_id',
                'pricing_mode',
                'pricing_context',
            ]);
        });
    }
};
