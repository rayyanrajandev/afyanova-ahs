<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('patient_insurance_records')) {
            Schema::table('patient_insurance_records', function (Blueprint $table): void {
                if (! Schema::hasColumn('patient_insurance_records', 'tenant_id')) {
                    $table->uuid('tenant_id')->nullable()->after('id')->index();
                }
                if (! Schema::hasColumn('patient_insurance_records', 'facility_id')) {
                    $table->uuid('facility_id')->nullable()->after('tenant_id')->index();
                }
                if (! Schema::hasColumn('patient_insurance_records', 'billing_payer_contract_id')) {
                    $table->uuid('billing_payer_contract_id')->nullable()->after('patient_id')->index();
                }
                if (! Schema::hasColumn('patient_insurance_records', 'provider_code')) {
                    $table->string('provider_code', 80)->nullable()->after('insurance_provider');
                }
                if (! Schema::hasColumn('patient_insurance_records', 'plan_name')) {
                    $table->string('plan_name', 160)->nullable()->after('provider_code');
                }
                if (! Schema::hasColumn('patient_insurance_records', 'principal_member_name')) {
                    $table->string('principal_member_name', 160)->nullable()->after('member_id');
                }
                if (! Schema::hasColumn('patient_insurance_records', 'relationship_to_principal')) {
                    $table->string('relationship_to_principal', 40)->nullable()->after('principal_member_name');
                }
                if (! Schema::hasColumn('patient_insurance_records', 'card_number')) {
                    $table->string('card_number', 120)->nullable()->after('relationship_to_principal');
                }
                if (! Schema::hasColumn('patient_insurance_records', 'copay_percent')) {
                    $table->decimal('copay_percent', 5, 2)->nullable()->after('coverage_level');
                }
                if (! Schema::hasColumn('patient_insurance_records', 'coverage_limit_amount')) {
                    $table->decimal('coverage_limit_amount', 14, 2)->nullable()->after('copay_percent');
                }
                if (! Schema::hasColumn('patient_insurance_records', 'verification_status')) {
                    $table->string('verification_status', 40)->default('unverified')->after('status')->index();
                }
                if (! Schema::hasColumn('patient_insurance_records', 'verification_source')) {
                    $table->string('verification_source', 80)->nullable()->after('verification_date');
                }
                if (! Schema::hasColumn('patient_insurance_records', 'verification_reference')) {
                    $table->string('verification_reference', 160)->nullable()->after('verification_source');
                }
                if (! Schema::hasColumn('patient_insurance_records', 'last_verified_at')) {
                    $table->timestamp('last_verified_at')->nullable()->after('verification_reference');
                }
                if (! Schema::hasColumn('patient_insurance_records', 'metadata')) {
                    $table->json('metadata')->nullable()->after('notes');
                }
            });

            Schema::table('patient_insurance_records', function (Blueprint $table): void {
                if (Schema::hasColumn('patient_insurance_records', 'billing_payer_contract_id')) {
                    $table->foreign('billing_payer_contract_id', 'patient_insurance_records_contract_fk')
                        ->references('id')
                        ->on('billing_payer_contracts')
                        ->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('patient_insurance_audit_events')) {
            Schema::create('patient_insurance_audit_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('patient_insurance_record_id')->index();
                $table->uuid('patient_id')->index();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 120)->index();
                $table->json('changes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable()->index();

                $table->foreign('patient_insurance_record_id', 'patient_insurance_audit_record_fk')
                    ->references('id')
                    ->on('patient_insurance_records')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('claims_insurance_cases')) {
            Schema::table('claims_insurance_cases', function (Blueprint $table): void {
                if (! Schema::hasColumn('claims_insurance_cases', 'patient_insurance_record_id')) {
                    $table->uuid('patient_insurance_record_id')->nullable()->after('patient_id')->index();
                }
                if (! Schema::hasColumn('claims_insurance_cases', 'payer_plan_name')) {
                    $table->string('payer_plan_name', 160)->nullable()->after('payer_name');
                }
                if (! Schema::hasColumn('claims_insurance_cases', 'member_id')) {
                    $table->string('member_id', 120)->nullable()->after('payer_reference');
                }
                if (! Schema::hasColumn('claims_insurance_cases', 'policy_number')) {
                    $table->string('policy_number', 120)->nullable()->after('member_id');
                }
                if (! Schema::hasColumn('claims_insurance_cases', 'card_number')) {
                    $table->string('card_number', 120)->nullable()->after('policy_number');
                }
                if (! Schema::hasColumn('claims_insurance_cases', 'verification_reference')) {
                    $table->string('verification_reference', 160)->nullable()->after('card_number');
                }
                if (! Schema::hasColumn('claims_insurance_cases', 'claim_readiness')) {
                    $table->json('claim_readiness')->nullable()->after('verification_reference');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('claims_insurance_cases')) {
            Schema::table('claims_insurance_cases', function (Blueprint $table): void {
                foreach ([
                    'claim_readiness',
                    'verification_reference',
                    'card_number',
                    'policy_number',
                    'member_id',
                    'payer_plan_name',
                    'patient_insurance_record_id',
                ] as $column) {
                    if (Schema::hasColumn('claims_insurance_cases', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('patient_insurance_audit_events');

        if (Schema::hasTable('patient_insurance_records')) {
            Schema::table('patient_insurance_records', function (Blueprint $table): void {
                if (Schema::hasColumn('patient_insurance_records', 'billing_payer_contract_id')) {
                    $table->dropForeign('patient_insurance_records_contract_fk');
                }
            });

            Schema::table('patient_insurance_records', function (Blueprint $table): void {
                foreach ([
                    'metadata',
                    'last_verified_at',
                    'verification_reference',
                    'verification_source',
                    'verification_status',
                    'coverage_limit_amount',
                    'copay_percent',
                    'card_number',
                    'relationship_to_principal',
                    'principal_member_name',
                    'plan_name',
                    'provider_code',
                    'billing_payer_contract_id',
                    'facility_id',
                    'tenant_id',
                ] as $column) {
                    if (Schema::hasColumn('patient_insurance_records', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
