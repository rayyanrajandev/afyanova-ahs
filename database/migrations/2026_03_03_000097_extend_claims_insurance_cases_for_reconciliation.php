<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims_insurance_cases', function (Blueprint $table): void {
            $table->decimal('claim_amount', 14, 2)->nullable()->after('payer_reference');
            $table->char('currency_code', 3)->nullable()->after('claim_amount');
            $table->decimal('settled_amount', 14, 2)->nullable()->after('rejected_amount');
            $table->timestamp('settled_at')->nullable()->after('settled_amount');
            $table->string('settlement_reference', 120)->nullable()->after('settled_at');
            $table->string('reconciliation_status', 30)->default('pending')->after('status');
            $table->text('reconciliation_notes')->nullable()->after('reconciliation_status');

            $table->index(['reconciliation_status', 'adjudicated_at'], 'claims_insurance_reconciliation_status_idx');
            $table->index(['payer_type', 'reconciliation_status', 'created_at'], 'claims_insurance_payer_reconciliation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('claims_insurance_cases', function (Blueprint $table): void {
            $table->dropIndex('claims_insurance_reconciliation_status_idx');
            $table->dropIndex('claims_insurance_payer_reconciliation_idx');
            $table->dropColumn([
                'claim_amount',
                'currency_code',
                'settled_amount',
                'settled_at',
                'settlement_reference',
                'reconciliation_status',
                'reconciliation_notes',
            ]);
        });
    }
};
