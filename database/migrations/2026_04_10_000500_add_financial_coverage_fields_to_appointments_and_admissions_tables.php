<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            if (! Schema::hasColumn('appointments', 'financial_coverage_type')) {
                $table->string('financial_coverage_type', 40)
                    ->nullable()
                    ->after('notes');
            }

            if (! Schema::hasColumn('appointments', 'billing_payer_contract_id')) {
                $table->uuid('billing_payer_contract_id')
                    ->nullable()
                    ->after('financial_coverage_type');
                $table->foreign('billing_payer_contract_id')
                    ->references('id')
                    ->on('billing_payer_contracts')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('appointments', 'coverage_reference')) {
                $table->string('coverage_reference', 160)
                    ->nullable()
                    ->after('billing_payer_contract_id');
            }

            if (! Schema::hasColumn('appointments', 'coverage_notes')) {
                $table->text('coverage_notes')
                    ->nullable()
                    ->after('coverage_reference');
            }
        });

        Schema::table('admissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('admissions', 'financial_coverage_type')) {
                $table->string('financial_coverage_type', 40)
                    ->nullable()
                    ->after('notes');
            }

            if (! Schema::hasColumn('admissions', 'billing_payer_contract_id')) {
                $table->uuid('billing_payer_contract_id')
                    ->nullable()
                    ->after('financial_coverage_type');
                $table->foreign('billing_payer_contract_id')
                    ->references('id')
                    ->on('billing_payer_contracts')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('admissions', 'coverage_reference')) {
                $table->string('coverage_reference', 160)
                    ->nullable()
                    ->after('billing_payer_contract_id');
            }

            if (! Schema::hasColumn('admissions', 'coverage_notes')) {
                $table->text('coverage_notes')
                    ->nullable()
                    ->after('coverage_reference');
            }
        });

        DB::table('appointments')
            ->whereNull('financial_coverage_type')
            ->update(['financial_coverage_type' => 'self_pay']);

        DB::table('admissions')
            ->whereNull('financial_coverage_type')
            ->update(['financial_coverage_type' => 'self_pay']);
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            if (Schema::hasColumn('admissions', 'coverage_notes')) {
                $table->dropColumn('coverage_notes');
            }

            if (Schema::hasColumn('admissions', 'coverage_reference')) {
                $table->dropColumn('coverage_reference');
            }

            if (Schema::hasColumn('admissions', 'billing_payer_contract_id')) {
                $table->dropForeign(['billing_payer_contract_id']);
                $table->dropColumn('billing_payer_contract_id');
            }

            if (Schema::hasColumn('admissions', 'financial_coverage_type')) {
                $table->dropColumn('financial_coverage_type');
            }
        });

        Schema::table('appointments', function (Blueprint $table): void {
            if (Schema::hasColumn('appointments', 'coverage_notes')) {
                $table->dropColumn('coverage_notes');
            }

            if (Schema::hasColumn('appointments', 'coverage_reference')) {
                $table->dropColumn('coverage_reference');
            }

            if (Schema::hasColumn('appointments', 'billing_payer_contract_id')) {
                $table->dropForeign(['billing_payer_contract_id']);
                $table->dropColumn('billing_payer_contract_id');
            }

            if (Schema::hasColumn('appointments', 'financial_coverage_type')) {
                $table->dropColumn('financial_coverage_type');
            }
        });
    }
};
