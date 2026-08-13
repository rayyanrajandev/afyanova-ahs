<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tables that have tenant_id and need RLS enabled.
     * Generated from codebase analysis of all migrations with tenant_id column.
     */
    private array $tenantScopedTables = [
        'admissions',
        'appointment_referrals',
        'appointments',
        'arrival_events',
        'audit_export_retry_resume_telemetry_events',
        'billing_corporate_accounts',
        'billing_corporate_invoice_runs',
        'billing_corporate_run_invoices',
        'billing_corporate_run_payments',
        'billing_daily_closes',
        'billing_discount_policies',
        'billing_discounts',
        'billing_invoices',
        'billing_nhif_claim_submissions',
        'billing_nhif_remittance_items',
        'billing_nhif_remittances',
        'billing_nhif_tariff_imports',
        'billing_nhif_verifications',
        'billing_payer_authorization_rules',
        'billing_payer_contract_price_overrides',
        'billing_payer_contracts',
        'billing_payment_gateway_transactions',
        'billing_payment_links',
        'billing_payment_plan_installments',
        'billing_payment_plans',
        'billing_report_export_jobs',
        'billing_service_catalog_items',
        'billing_sms_logs',
        'billing_tra_receipts',
        'cash_billing_accounts',
        'cash_billing_charges',
        'cash_billing_payments',
        'chargeable_items',
        'claims_insurance_cases',
        'clinical_catalog_consumption_recipe_items',
        'clinical_order_sessions',
        'clinical_privilege_catalog_audit_logs',
        'clinical_privilege_catalogs',
        'clinical_procedure_order_audit_logs',
        'clinical_procedure_orders',
        'clinical_specialties',
        'clinical_specialty_audit_logs',
        'departments',
        'department_stock_balances',
        'department_stock_movements',
        'emergency_triage_cases',
        'emergency_triage_case_transfers',
        'encounter_clinical_documents',
        'encounters',
        'facilities',
        'facility_rollout_plans',
        'facility_rollout_incidents',
        'inventory_procurement_orders',
        'inventory_procurement_order_items',
        'inventory_procurement_requisitions',
        'inventory_procurement_requisition_items',
        'inventory_procurement_vendors',
        'inventory_stock_balances',
        'inventory_stock_movements',
        'inventory_stock_takes',
        'inventory_stock_take_items',
        'inventory_warehouses',
        'lab_orders',
        'lab_order_results',
        'lab_order_specimens',
        'lab_panels',
        'lab_test_catalog_items',
        'lab_test_panels',
        'medical_records',
        'medication_administrations',
        'medication_discontinuations',
        'medication_dispenses',
        'medication_orders',
        'nursing_care_plans',
        'nursing_observations',
        'nursing_tasks',
        'patient_allergies',
        'patient_consents',
        'patient_diagnoses',
        'patient_insurance_coverages',
        'patient_medical_histories',
        'patient_notes',
        'patient_observations',
        'patient_problems',
        'patient_vaccinations',
        'patients',
        'pharmacy_order_items',
        'pharmacy_orders',
        'pharmacy_stock_balances',
        'pharmacy_stock_movements',
        'platform_clinical_catalog_items',
        'platform_user_approval_cases',
        'prescriptions',
        'radiology_orders',
        'radiology_order_results',
        'referrals',
        'service_requests',
        'staff_profiles',
        'surgery_orders',
        'surgery_order_resource_allocations',
        'system_settings',
        'theatre_procedures',
        'theatre_procedure_resource_allocations',
        'triage_case_notes',
        'user_facilities',
        'user_sessions',
        'visit_notes',
        'visits',
        'ward_admissions',
        'ward_beds',
        'ward_room_transfers',
        'wards',
    ];

    public function up(): void
    {
        // RLS is a PostgreSQL-only feature. Skip on other drivers (SQLite for tests, etc.)
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantScopedTables as $table) {
            $policyName = "tenant_isolation_policy_{$table}";
            $bypassPolicyName = "tenant_isolation_bypass_{$table}";

            // Enable RLS on the table
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;");

            // Drop existing policy if any (idempotent)
            DB::statement("DROP POLICY IF EXISTS {$policyName} ON {$table};");
            DB::statement("DROP POLICY IF EXISTS {$bypassPolicyName} ON {$table};");

            // Create the tenant isolation policy
            DB::statement("
                CREATE POLICY {$policyName} ON {$table}
                    FOR ALL
                    USING (tenant_id::text = current_setting('app.tenant_id')::text)
                    WITH CHECK (tenant_id::text = current_setting('app.tenant_id')::text);
            ");

            // Create the bypass policy for platform super-admins
            DB::statement("
                CREATE POLICY {$bypassPolicyName} ON {$table}
                    FOR ALL
                    USING (current_setting('app.bypass_tenant_isolation') = 'true')
                    WITH CHECK (current_setting('app.bypass_tenant_isolation') = 'true');
            ");
        }
    }

    public function down(): void
    {
        // RLS is a PostgreSQL-only feature. Skip on other drivers (SQLite for tests, etc.)
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantScopedTables as $table) {
            $policyName = "tenant_isolation_policy_{$table}";
            $bypassPolicyName = "tenant_isolation_bypass_{$table}";

            DB::statement("DROP POLICY IF EXISTS {$policyName} ON {$table};");
            DB::statement("DROP POLICY IF EXISTS {$bypassPolicyName} ON {$table};");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;");
        }
    }
};