<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payer_authorization_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('billing_payer_authorization_rules', 'coverage_decision')) {
                $table->string('coverage_decision', 30)
                    ->nullable()
                    ->after('quantity_limit');
            }

            if (! Schema::hasColumn('billing_payer_authorization_rules', 'coverage_percent_override')) {
                $table->decimal('coverage_percent_override', 5, 2)
                    ->nullable()
                    ->after('coverage_decision');
            }

            if (! Schema::hasColumn('billing_payer_authorization_rules', 'copay_type')) {
                $table->string('copay_type', 20)
                    ->nullable()
                    ->after('coverage_percent_override');
            }

            if (! Schema::hasColumn('billing_payer_authorization_rules', 'copay_value')) {
                $table->decimal('copay_value', 12, 2)
                    ->nullable()
                    ->after('copay_type');
            }

            if (! Schema::hasColumn('billing_payer_authorization_rules', 'benefit_limit_amount')) {
                $table->decimal('benefit_limit_amount', 14, 2)
                    ->nullable()
                    ->after('copay_value');
            }

            if (! Schema::hasColumn('billing_payer_authorization_rules', 'effective_from')) {
                $table->dateTime('effective_from')
                    ->nullable()
                    ->after('benefit_limit_amount');
            }

            if (! Schema::hasColumn('billing_payer_authorization_rules', 'effective_to')) {
                $table->dateTime('effective_to')
                    ->nullable()
                    ->after('effective_from');
            }
        });

        DB::table('billing_payer_authorization_rules')
            ->whereNull('coverage_decision')
            ->update([
                'coverage_decision' => 'covered_with_rule',
            ]);

        Schema::table('billing_payer_authorization_rules', function (Blueprint $table): void {
            $table->index(['coverage_decision', 'status'], 'billing_payer_authorization_rules_coverage_decision_status_idx');
            $table->index(['effective_from', 'effective_to'], 'billing_payer_authorization_rules_effective_window_idx');
        });
    }

    public function down(): void
    {
        Schema::table('billing_payer_authorization_rules', function (Blueprint $table): void {
            $table->dropIndex('billing_payer_authorization_rules_effective_window_idx');
            $table->dropIndex('billing_payer_authorization_rules_coverage_decision_status_idx');

            foreach ([
                'effective_to',
                'effective_from',
                'benefit_limit_amount',
                'copay_value',
                'copay_type',
                'coverage_percent_override',
                'coverage_decision',
            ] as $column) {
                if (Schema::hasColumn('billing_payer_authorization_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

};
