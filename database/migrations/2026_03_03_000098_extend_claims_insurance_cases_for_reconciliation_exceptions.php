<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims_insurance_cases', function (Blueprint $table): void {
            $table->decimal('reconciliation_shortfall_amount', 14, 2)->nullable()->after('settled_amount');
            $table->string('reconciliation_exception_status', 30)->default('none')->after('reconciliation_status');
            $table->string('reconciliation_follow_up_status', 30)->default('none')->after('reconciliation_exception_status');
            $table->timestamp('reconciliation_follow_up_due_at')->nullable()->after('reconciliation_follow_up_status');
            $table->text('reconciliation_follow_up_note')->nullable()->after('reconciliation_follow_up_due_at');
            $table->timestamp('reconciliation_follow_up_updated_at')->nullable()->after('reconciliation_follow_up_note');
            $table->unsignedBigInteger('reconciliation_follow_up_updated_by_user_id')->nullable()->after('reconciliation_follow_up_updated_at');

            $table->index(
                ['reconciliation_exception_status', 'reconciliation_follow_up_status', 'reconciliation_follow_up_due_at'],
                'claims_insurance_reconciliation_exception_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('claims_insurance_cases', function (Blueprint $table): void {
            $table->dropIndex('claims_insurance_reconciliation_exception_idx');
            $table->dropColumn([
                'reconciliation_shortfall_amount',
                'reconciliation_exception_status',
                'reconciliation_follow_up_status',
                'reconciliation_follow_up_due_at',
                'reconciliation_follow_up_note',
                'reconciliation_follow_up_updated_at',
                'reconciliation_follow_up_updated_by_user_id',
            ]);
        });
    }
};
