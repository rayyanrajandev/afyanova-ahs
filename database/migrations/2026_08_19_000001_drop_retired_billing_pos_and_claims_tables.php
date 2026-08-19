<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier Workspace, Phase 2 — drop the retired billing, POS and claims
 * schema.
 *
 * All 48 tables below held zero rows at the time this was written, and had
 * held zero rows for their entire existence: no invoice, payment, POS sale,
 * cash charge or insurance claim was ever recorded in this system. The code
 * that wrote them was deleted in the same phase, and its HTTP surface had
 * already been removed by 29e52b6. Nothing reads them.
 *
 * Deliberately NOT dropped, because the prepaid ledger keeps using them:
 *   - chargeable_items, price_book_entries, price_book_entry_audit_logs
 *     (the priced service catalogue — 237 items, 237 active TZS tariffs)
 *   - billing_payer_contracts + its audit log (payer-agnostic, and the target
 *     of live FKs from appointments and admissions; the table name is left
 *     alone precisely because renaming it would touch those)
 *   - patient_insurance_records + patient_insurance_audit_events (membership
 *     capture, still routed under reception)
 *
 * Order is topological — every table is dropped before the tables it points
 * at — so no foreign key is ever left dangling mid-migration. Verified before
 * writing: no surviving table holds a foreign key into any table dropped here.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const DROP_IN_ORDER = [
        'billing_corporate_run_invoices',
        'billing_corporate_run_payments',
        'billing_corporate_invoice_runs',
        'billing_corporate_accounts',
        'billing_daily_closes',
        'billing_discounts',
        'billing_discount_policies',
        'billing_invoice_audit_logs',
        'billing_invoice_line_item_sources',
        'billing_payment_plan_installments',
        'billing_refund_audit_logs',
        'billing_refunds',
        'billing_invoice_payments',
        'billing_payment_plans',
        'billing_source_status',
        'billing_write_offs',
        'claims_insurance_case_audit_logs',
        'claims_insurance_cases',
        'revenue_recognition_records',
        'billing_invoices',
        'billing_nhif_claim_submissions',
        'billing_nhif_remittance_items',
        'billing_nhif_remittances',
        'billing_nhif_tariff_imports',
        'billing_nhif_verifications',
        'billing_payer_authorization_rule_audit_logs',
        'billing_payer_authorization_rules',
        'billing_payer_contract_price_override_audit_logs',
        'billing_payer_contract_price_overrides',
        'billing_payment_gateway_transactions',
        'billing_payment_links',
        'billing_report_export_jobs',
        'billing_service_catalog_item_audit_logs',
        'consultation_mappings',
        'billing_service_catalog_items',
        'billing_sms_logs',
        'billing_tra_receipts',
        'cash_billing_charges',
        'cash_billing_payments',
        'cash_billing_accounts',
        'gl_journal_entries',
        'pos_cafeteria_menu_items',
        'pos_sale_adjustments',
        'pos_sale_lines',
        'pos_sale_payments',
        'pos_sales',
        'pos_register_sessions',
        'pos_registers',
    ];

    public function up(): void
    {
        foreach (self::DROP_IN_ORDER as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Intentionally a no-op rather than a rebuild.
     *
     * Recreating 48 empty legacy tables would mean reproducing the schema of
     * 87 historical migrations, and would restore nothing of value — there is
     * no data to come back to. Rolling back and re-migrating is still
     * consistent: the create migrations that precede this one are not re-run,
     * and dropIfExists is idempotent, so the end state is identical either way.
     *
     * Genuine recovery is `git revert` of the Phase 2 commit, which restores
     * both the code and these migrations together.
     */
    public function down(): void
    {
        // No-op. See the docblock above.
    }
};
