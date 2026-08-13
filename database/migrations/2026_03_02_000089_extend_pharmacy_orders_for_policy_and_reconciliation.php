<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $table->string('formulary_decision_status', 32)->default('not_reviewed')->after('verification_note');
            $table->string('formulary_decision_reason', 1000)->nullable()->after('formulary_decision_status');
            $table->dateTime('formulary_reviewed_at')->nullable()->after('formulary_decision_reason');
            $table->unsignedBigInteger('formulary_reviewed_by_user_id')->nullable()->after('formulary_reviewed_at');

            $table->boolean('substitution_allowed')->default(false)->after('formulary_reviewed_by_user_id');
            $table->boolean('substitution_made')->default(false)->after('substitution_allowed');
            $table->string('substituted_medication_code', 100)->nullable()->after('substitution_made');
            $table->string('substituted_medication_name', 255)->nullable()->after('substituted_medication_code');
            $table->string('substitution_reason', 1000)->nullable()->after('substituted_medication_name');
            $table->dateTime('substitution_approved_at')->nullable()->after('substitution_reason');
            $table->unsignedBigInteger('substitution_approved_by_user_id')->nullable()->after('substitution_approved_at');

            $table->string('reconciliation_status', 32)->default('pending')->after('substitution_approved_by_user_id');
            $table->string('reconciliation_note', 1000)->nullable()->after('reconciliation_status');
            $table->dateTime('reconciled_at')->nullable()->after('reconciliation_note');
            $table->unsignedBigInteger('reconciled_by_user_id')->nullable()->after('reconciled_at');

            $table->index('formulary_decision_status', 'pharmacy_orders_formulary_decision_status_idx');
            $table->index('reconciliation_status', 'pharmacy_orders_reconciliation_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $table->dropIndex('pharmacy_orders_formulary_decision_status_idx');
            $table->dropIndex('pharmacy_orders_reconciliation_status_idx');

            $table->dropColumn([
                'formulary_decision_status',
                'formulary_decision_reason',
                'formulary_reviewed_at',
                'formulary_reviewed_by_user_id',
                'substitution_allowed',
                'substitution_made',
                'substituted_medication_code',
                'substituted_medication_name',
                'substitution_reason',
                'substitution_approved_at',
                'substitution_approved_by_user_id',
                'reconciliation_status',
                'reconciliation_note',
                'reconciled_at',
                'reconciled_by_user_id',
            ]);
        });
    }
};
