<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * @var array<string, array{name: string, description: string, price_amount: string, sort_order: int}>
     */
    private array $plans = [
        'patient_registration' => [
            'name' => 'Clinic Starter',
            'description' => 'Small clinic or pilot facility foundation for patient access, appointments, basic cashiering, receipts, daily cash visibility, and local facility setup.',
            'price_amount' => '150000.00',
            'sort_order' => 10,
        ],
        'front_desk_billing' => [
            'name' => 'Front Office & Revenue',
            'description' => 'Private-clinic revenue desk package for registration, appointments, admissions intake, invoices, payments, POS, payer contracts, insurance claims, and TRA-ready receipt operations.',
            'price_amount' => '350000.00',
            'sort_order' => 20,
        ],
        'clinical_operations' => [
            'name' => 'Private Hospital Operations',
            'description' => 'Full single-facility hospital operations for OPD, emergency, admissions, medical records, lab, radiology, pharmacy, theatre, wards, inventory, staff governance, and operational reporting.',
            'price_amount' => '900000.00',
            'sort_order' => 30,
        ],
        'hospital_network' => [
            'name' => 'Enterprise Hospital Network',
            'description' => 'Advanced private hospital group package with all hospital operations plus multi-facility controls, rollout governance, integrations, advanced audit exports, executive reporting, and data-governance controls.',
            'price_amount' => '1800000.00',
            'sort_order' => 40,
        ],
    ];

    /**
     * @var array<string, array{name: string, description: string, price_amount: string, sort_order: int}>
     */
    private array $legacyPlans = [
        'patient_registration' => [
            'name' => 'Patient Access Starter',
            'description' => 'Entry plan for registration, patient search, demographics, and facility administration.',
            'price_amount' => '75000.00',
            'sort_order' => 10,
        ],
        'front_desk_billing' => [
            'name' => 'Front Office Essentials',
            'description' => 'Patient access plus appointments, cashier billing, receipts, and daily cash reporting.',
            'price_amount' => '175000.00',
            'sort_order' => 20,
        ],
        'clinical_operations' => [
            'name' => 'Clinical Operations Plus',
            'description' => 'Front office and billing plus clinical encounters, orders, pharmacy, laboratory, and stock issue workflows.',
            'price_amount' => '450000.00',
            'sort_order' => 30,
        ],
        'hospital_network' => [
            'name' => 'Enterprise Hospital Network',
            'description' => 'Full hospital operations with network controls, cross-facility reporting, integrations, and advanced audit access.',
            'price_amount' => '900000.00',
            'sort_order' => 40,
        ],
    ];

    /**
     * @var array<int, array{key: string, label: string, group: string, permissions?: array<int, string>}>
     */
    private array $catalog = [
        ['key' => 'patients.registration', 'label' => 'Patient registration', 'group' => 'Patient Access', 'permissions' => ['patients.read', 'patients.create']],
        ['key' => 'patients.search', 'label' => 'Patient search and chart lookup', 'group' => 'Patient Access', 'permissions' => ['patients.read', 'patients.view-audit-logs']],
        ['key' => 'patients.demographics', 'label' => 'Demographics and patient status maintenance', 'group' => 'Patient Access', 'permissions' => ['patients.update', 'patients.update-status']],
        ['key' => 'patients.medication_safety', 'label' => 'Allergies, medication profile, and reconciliation', 'group' => 'Patient Access', 'permissions' => ['patients.read', 'patients.update']],

        ['key' => 'appointments.scheduling', 'label' => 'Appointments, queues, and scheduling', 'group' => 'Front Office', 'permissions' => ['appointments.read', 'appointments.create', 'appointments.update', 'appointments.update-status', 'appointments.record-triage', 'appointments.view-audit-logs']],
        ['key' => 'appointments.provider_sessions', 'label' => 'Provider sessions and consultation start', 'group' => 'Front Office', 'permissions' => ['appointments.manage-provider-session', 'appointments.start-consultation']],
        ['key' => 'appointments.referrals', 'label' => 'Referral management and referral audit', 'group' => 'Front Office', 'permissions' => ['appointments.manage-referrals', 'appointments.view-referral-audit-logs']],
        ['key' => 'admissions.management', 'label' => 'Admissions, bed occupancy, and admission audit', 'group' => 'Front Office', 'permissions' => ['admissions.read', 'admissions.create', 'admissions.update', 'admissions.update-status', 'admissions.view-audit-logs']],

        ['key' => 'emergency.triage', 'label' => 'Emergency triage and transfer desk', 'group' => 'Care Delivery', 'permissions' => ['emergency.triage.read', 'emergency.triage.create', 'emergency.triage.update', 'emergency.triage.update-status', 'emergency.triage.manage-transfers', 'emergency.triage.view-transfer-audit-logs', 'emergency.triage.view-audit-logs']],
        ['key' => 'clinical.encounters', 'label' => 'Clinical encounters', 'group' => 'Care Delivery', 'permissions' => ['medical.records.read', 'medical.records.create', 'medical.records.update']],
        ['key' => 'clinical.orders', 'label' => 'Clinical order entry', 'group' => 'Care Delivery', 'permissions' => ['laboratory.orders.create', 'pharmacy.orders.create', 'radiology.orders.create', 'theatre.procedures.create']],
        ['key' => 'medical_records.core', 'label' => 'Medical records workspace', 'group' => 'Care Delivery', 'permissions' => ['medical.records.read', 'medical.records.create', 'medical.records.update']],
        ['key' => 'medical_records.governance', 'label' => 'Record finalization, attestation, archive, amendment, and audit', 'group' => 'Care Delivery', 'permissions' => ['medical.records.finalize', 'medical.records.attest', 'medical.records.archive', 'medical.records.amend', 'medical-records.view-audit-logs']],
        ['key' => 'laboratory.orders', 'label' => 'Laboratory orders, results, and verification', 'group' => 'Diagnostics', 'permissions' => ['laboratory.orders.read', 'laboratory.orders.create', 'laboratory.orders.update-status', 'laboratory.orders.verify-result', 'laboratory.orders.view-audit-logs', 'laboratory-orders.view-audit-logs']],
        ['key' => 'radiology.orders', 'label' => 'Radiology orders, worklist, and results', 'group' => 'Diagnostics', 'permissions' => ['radiology.orders.read', 'radiology.orders.create', 'radiology.orders.update', 'radiology.orders.update-status', 'radiology.orders.view-audit-logs', 'radiology-orders.view-audit-logs']],
        ['key' => 'pharmacy.orders', 'label' => 'Pharmacy orders and dispensing workflow', 'group' => 'Pharmacy', 'permissions' => ['pharmacy.orders.read', 'pharmacy.orders.create', 'pharmacy.orders.update-status', 'pharmacy.orders.manage-policy', 'pharmacy.orders.reconcile', 'pharmacy.orders.verify-dispense', 'pharmacy.orders.view-audit-logs', 'pharmacy-orders.view-audit-logs']],
        ['key' => 'pharmacy.dispensing', 'label' => 'Pharmacy dispensing', 'group' => 'Pharmacy', 'permissions' => ['pharmacy.orders.create', 'pharmacy.orders.verify-dispense']],
        ['key' => 'theatre.procedures', 'label' => 'Theatre procedures and resource allocation', 'group' => 'Care Delivery', 'permissions' => ['theatre.procedures.read', 'theatre.procedures.create', 'theatre.procedures.update-status', 'theatre.procedures.manage-resources', 'theatre.procedures.view-resource-audit-logs', 'theatre.procedures.view-audit-logs']],
        ['key' => 'inpatient.ward', 'label' => 'Inpatient ward census and nursing workspace', 'group' => 'Ward Operations', 'permissions' => ['inpatient.ward.read', 'inpatient.ward.view-audit-logs']],
        ['key' => 'inpatient.tasks', 'label' => 'Ward tasks and round notes', 'group' => 'Ward Operations', 'permissions' => ['inpatient.ward.create-task', 'inpatient.ward.update-task-status', 'inpatient.ward.create-round-note']],
        ['key' => 'inpatient.care_plans', 'label' => 'Care plans and discharge checklists', 'group' => 'Ward Operations', 'permissions' => ['inpatient.ward.create-care-plan', 'inpatient.ward.update-care-plan', 'inpatient.ward.update-care-plan-status', 'inpatient.ward.manage-discharge-checklist']],

        ['key' => 'billing.cashier', 'label' => 'Cashier billing', 'group' => 'Revenue Cycle', 'permissions' => ['billing.payments.record', 'billing.payments.view-history']],
        ['key' => 'billing.receipts', 'label' => 'Receipts and payment history', 'group' => 'Revenue Cycle', 'permissions' => ['billing.payments.view-history']],
        ['key' => 'billing.invoices', 'label' => 'Invoices, charge capture, and invoice documents', 'group' => 'Revenue Cycle', 'permissions' => ['billing.invoices.read', 'billing.invoices.create', 'billing.invoices.issue', 'billing.invoices.update-draft', 'billing.invoices.cancel']],
        ['key' => 'billing.payments', 'label' => 'Payment collection, reversals, and history', 'group' => 'Revenue Cycle', 'permissions' => ['billing.payments.record', 'billing.payments.reverse', 'billing.payments.view-history']],
        ['key' => 'billing.cash_accounts', 'label' => 'Cash accounts and till controls', 'group' => 'Revenue Cycle', 'permissions' => ['billing.cash-accounts.read', 'billing.cash-accounts.manage']],
        ['key' => 'billing.payment_plans', 'label' => 'Patient payment plans', 'group' => 'Revenue Cycle', 'permissions' => ['billing.invoices.read', 'billing.payments.record']],
        ['key' => 'billing.discounts_refunds', 'label' => 'Discounts, refunds, approvals, and processing', 'group' => 'Revenue Cycle', 'permissions' => ['billing.discounts.read', 'billing.discounts.manage', 'billing.refunds.read', 'billing.refunds.create', 'billing.refunds.approve', 'billing.refunds.process']],
        ['key' => 'billing.financial_controls', 'label' => 'Financial controls, voids, audit, and finance reporting', 'group' => 'Revenue Cycle', 'permissions' => ['billing.financial-controls.read', 'billing.invoices.void', 'billing-invoices.view-audit-logs']],
        ['key' => 'billing.service_catalog', 'label' => 'Billing service catalog and pricing', 'group' => 'Revenue Cycle', 'permissions' => ['billing.service-catalog.read', 'billing.service-catalog.manage-identity', 'billing.service-catalog.manage-pricing', 'billing.service-catalog.view-audit-logs']],
        ['key' => 'billing.payer_contracts', 'label' => 'Corporate payer contracts, tariffs, and authorization rules', 'group' => 'Revenue Cycle', 'permissions' => ['billing.payer-contracts.read', 'billing.payer-contracts.manage', 'billing.payer-contracts.view-audit-logs', 'billing.payer-contracts.manage-price-overrides', 'billing.payer-contracts.view-price-override-audit-logs', 'billing.payer-contracts.manage-authorization-rules', 'billing.payer-contracts.view-authorization-audit-logs']],
        ['key' => 'billing.revenue_cycle', 'label' => 'Revenue cycle management', 'group' => 'Revenue Cycle', 'permissions' => ['billing.routing.read', 'billing.financial-controls.read']],
        ['key' => 'claims.insurance', 'label' => 'Insurance and NHIF-style claims workflow', 'group' => 'Revenue Cycle', 'permissions' => ['claims.insurance.read', 'claims.insurance.create', 'claims.insurance.update', 'claims.insurance.update-status', 'claims.insurance.view-audit-logs']],
        ['key' => 'fiscal_receipts.tra', 'label' => 'TRA fiscal receipt readiness controls', 'group' => 'Revenue Cycle', 'permissions' => ['billing.payments.record', 'pos.sales.read']],

        ['key' => 'pos.registers_sessions', 'label' => 'POS registers and cashier sessions', 'group' => 'Point of Sale', 'permissions' => ['pos.registers.read', 'pos.registers.manage', 'pos.sessions.read', 'pos.sessions.manage']],
        ['key' => 'pos.sales', 'label' => 'POS sales, voids, refunds, and sale documents', 'group' => 'Point of Sale', 'permissions' => ['pos.sales.read', 'pos.sales.create', 'pos.sales.void', 'pos.sales.refund']],
        ['key' => 'pos.lab_quick', 'label' => 'POS laboratory quick sale', 'group' => 'Point of Sale', 'permissions' => ['pos.lab-quick.read', 'pos.lab-quick.create']],
        ['key' => 'pos.cafeteria', 'label' => 'POS cafeteria sales and catalog', 'group' => 'Point of Sale', 'permissions' => ['pos.cafeteria.read', 'pos.cafeteria.create', 'pos.cafeteria.manage-catalog']],
        ['key' => 'pos.pharmacy_otc', 'label' => 'POS pharmacy OTC sales', 'group' => 'Point of Sale', 'permissions' => ['pos.pharmacy-otc.read', 'pos.pharmacy-otc.create']],

        ['key' => 'inventory.stock_issue', 'label' => 'Clinical stock issue', 'group' => 'Inventory & Procurement', 'permissions' => ['inventory.procurement.read', 'inventory.procurement.create-request']],
        ['key' => 'inventory.items', 'label' => 'Inventory item master and stock catalog', 'group' => 'Inventory & Procurement', 'permissions' => ['inventory.procurement.read', 'inventory.procurement.manage-items']],
        ['key' => 'inventory.stock_movements', 'label' => 'Stock movements and reconciliation', 'group' => 'Inventory & Procurement', 'permissions' => ['inventory.procurement.create-movement', 'inventory.procurement.reconcile-stock']],
        ['key' => 'inventory.requisitions', 'label' => 'Department requisitions and approvals', 'group' => 'Inventory & Procurement', 'permissions' => ['inventory.procurement.create-request', 'inventory.procurement.update-request-status']],
        ['key' => 'inventory.procurement', 'label' => 'Inventory and procurement', 'group' => 'Inventory & Procurement', 'permissions' => ['inventory.procurement.read', 'inventory.procurement.manage-items', 'inventory.procurement.create-request']],
        ['key' => 'inventory.suppliers', 'label' => 'Supplier management', 'group' => 'Inventory & Procurement', 'permissions' => ['inventory.procurement.manage-suppliers']],
        ['key' => 'inventory.warehouses', 'label' => 'Warehouse management', 'group' => 'Inventory & Procurement', 'permissions' => ['inventory.procurement.manage-warehouses']],
        ['key' => 'inventory.transfers', 'label' => 'Warehouse transfers, pick slips, and dispatch notes', 'group' => 'Inventory & Procurement', 'permissions' => ['inventory.procurement.read', 'inventory.procurement.create-movement']],
        ['key' => 'inventory.analytics', 'label' => 'Inventory analytics and audit logs', 'group' => 'Inventory & Procurement', 'permissions' => ['inventory.procurement.view-audit-logs']],

        ['key' => 'staff.directory', 'label' => 'Clinical staff directory', 'group' => 'People & Credentialing', 'permissions' => ['staff.clinical-directory.read']],
        ['key' => 'staff.profiles', 'label' => 'Staff profiles and employment status', 'group' => 'People & Credentialing', 'permissions' => ['staff.read', 'staff.create', 'staff.update', 'staff.update-status', 'staff.view-audit-logs']],
        ['key' => 'staff.documents', 'label' => 'Staff document management and verification', 'group' => 'People & Credentialing', 'permissions' => ['staff.documents.read', 'staff.documents.create', 'staff.documents.update', 'staff.documents.verify', 'staff.documents.update-status', 'staff.documents.view-audit-logs']],
        ['key' => 'staff.credentialing', 'label' => 'Professional credentialing and registrations', 'group' => 'People & Credentialing', 'permissions' => ['staff.credentialing.read', 'staff.credentialing.manage-profile', 'staff.credentialing.manage-registrations', 'staff.credentialing.verify', 'staff.credentialing.view-audit-logs']],
        ['key' => 'staff.privileges', 'label' => 'Clinical privileges and privilege catalogs', 'group' => 'People & Credentialing', 'permissions' => ['staff.privileges.read', 'staff.privileges.create', 'staff.privileges.update', 'staff.privileges.review', 'staff.privileges.update-status', 'staff.privileges.approve', 'staff.privileges.view-audit-logs']],

        ['key' => 'departments.management', 'label' => 'Department management', 'group' => 'Facility Setup', 'permissions' => ['departments.read', 'departments.create', 'departments.update', 'departments.update-status', 'departments.view-audit-logs']],
        ['key' => 'clinical.specialties', 'label' => 'Clinical specialties and staff specialty assignments', 'group' => 'Facility Setup', 'permissions' => ['specialties.read', 'specialties.create', 'specialties.update', 'specialties.update-status', 'specialties.view-audit-logs', 'staff.specialties.read', 'staff.specialties.manage']],
        ['key' => 'platform.facility_admin', 'label' => 'Facility administration', 'group' => 'Facility Setup', 'permissions' => ['platform.facilities.read', 'platform.facilities.create', 'platform.facilities.update', 'platform.facilities.update-status', 'platform.facilities.manage-owners', 'platform.facilities.view-audit-logs']],
        ['key' => 'platform.resource_registry', 'label' => 'Service points and ward bed registry', 'group' => 'Facility Setup', 'permissions' => ['platform.resources.read', 'platform.resources.manage-service-points', 'platform.resources.manage-ward-beds', 'platform.resources.view-audit-logs']],
        ['key' => 'platform.clinical_catalog', 'label' => 'Clinical catalog governance', 'group' => 'Facility Setup', 'permissions' => ['platform.clinical-catalog.read', 'platform.clinical-catalog.manage-lab-tests', 'platform.clinical-catalog.manage-radiology-procedures', 'platform.clinical-catalog.manage-theatre-procedures', 'platform.clinical-catalog.manage-clinical-procedures', 'platform.clinical-catalog.manage-formulary', 'platform.clinical-catalog.view-audit-logs']],
        ['key' => 'platform.user_security', 'label' => 'Users, access assignments, and approval cases', 'group' => 'Platform Administration', 'permissions' => ['platform.users.read', 'platform.users.create', 'platform.users.update', 'platform.users.update-status', 'platform.users.manage-facilities', 'platform.users.reset-password', 'platform.users.view-audit-logs', 'platform.users.approval-cases.read', 'platform.users.approval-cases.create', 'platform.users.approval-cases.manage', 'platform.users.approval-cases.review', 'platform.users.approval-cases.view-audit-logs']],
        ['key' => 'platform.rbac', 'label' => 'Roles, permissions, and RBAC audit', 'group' => 'Platform Administration', 'permissions' => ['platform.rbac.read', 'platform.rbac.manage-roles', 'platform.rbac.manage-user-roles', 'platform.rbac.view-audit-logs']],
        ['key' => 'platform.branding', 'label' => 'System branding and mail branding', 'group' => 'Platform Administration', 'permissions' => ['platform.settings.manage-branding']],
        ['key' => 'platform.feature_flags', 'label' => 'Feature flags and operational overrides', 'group' => 'Platform Administration', 'permissions' => ['platform.feature-flag-overrides.manage', 'platform.feature-flag-overrides.view-audit-logs']],
        ['key' => 'platform.subscription_admin', 'label' => 'Subscription plan and facility subscription administration', 'group' => 'Platform Administration', 'permissions' => ['platform.subscription-plans.read', 'platform.subscription-plans.manage', 'platform.subscription-plans.view-audit-logs', 'platform.facilities.manage-subscriptions']],
        ['key' => 'multi_facility.operations', 'label' => 'Multi-facility operations', 'group' => 'Platform Administration', 'permissions' => ['platform.multi-facility.read', 'platform.multi-facility.manage-rollouts', 'platform.multi-facility.view-audit-logs']],
        ['key' => 'facility.rollouts', 'label' => 'Rollout checkpoints, incidents, acceptance, and rollback', 'group' => 'Platform Administration', 'permissions' => ['platform.multi-facility.manage-rollouts', 'platform.multi-facility.manage-incidents', 'platform.multi-facility.execute-rollback', 'platform.multi-facility.approve-acceptance', 'platform.multi-facility.view-audit-logs']],

        ['key' => 'audit.advanced', 'label' => 'Advanced audit and export', 'group' => 'Governance & Compliance', 'permissions' => ['platform.cross-tenant.write', 'platform.cross-tenant.view-audit-logs', 'platform.rbac.view-audit-logs']],
        ['key' => 'audit.exports', 'label' => 'Audit export jobs, retry telemetry, and retention evidence', 'group' => 'Governance & Compliance', 'permissions' => ['platform.audit-export-jobs.cleanup', 'platform.audit-export-retry-resume-telemetry.cleanup', 'platform.cross-tenant-admin-audit-logs.retention-purge-scheduled', 'platform.cross-tenant.view-audit-logs']],
        ['key' => 'data_privacy.governance', 'label' => 'Data privacy and governance controls', 'group' => 'Governance & Compliance', 'permissions' => ['platform.cross-tenant.manage-audit-holds', 'platform.cross-tenant.view-audit-holds']],
        ['key' => 'integrations.interoperability', 'label' => 'Integration adapters', 'group' => 'Interoperability', 'permissions' => ['platform.cross-tenant.read']],
        ['key' => 'integrations.health_insurance', 'label' => 'Insurance and payer integration readiness', 'group' => 'Interoperability', 'permissions' => ['claims.insurance.read', 'billing.payer-contracts.read']],
        ['key' => 'integrations.national_reporting', 'label' => 'National reporting interoperability readiness', 'group' => 'Interoperability', 'permissions' => ['platform.cross-tenant.read']],

        ['key' => 'reports.daily_cash', 'label' => 'Daily cash reports', 'group' => 'Reporting', 'permissions' => ['billing.cash-accounts.read', 'pos.sessions.read']],
        ['key' => 'reports.revenue_cycle', 'label' => 'Revenue cycle reports', 'group' => 'Reporting', 'permissions' => ['billing.financial-controls.read', 'claims.insurance.read']],
        ['key' => 'reports.operational', 'label' => 'Operational reports', 'group' => 'Reporting', 'permissions' => ['admissions.read', 'laboratory.orders.read', 'pharmacy.orders.read', 'inventory.procurement.read']],
        ['key' => 'reports.executive', 'label' => 'Executive reporting', 'group' => 'Reporting', 'permissions' => ['platform.cross-tenant.read', 'billing.financial-controls.read']],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private array $planEntitlements = [
        'patient_registration' => [
            'patients.registration',
            'patients.search',
            'patients.demographics',
            'patients.medication_safety',
            'appointments.scheduling',
            'billing.cashier',
            'billing.receipts',
            'billing.invoices',
            'billing.payments',
            'billing.cash_accounts',
            'pos.registers_sessions',
            'pos.sales',
            'fiscal_receipts.tra',
            'staff.directory',
            'departments.management',
            'clinical.specialties',
            'platform.facility_admin',
            'reports.daily_cash',
        ],
        'front_desk_billing' => [
            'patients.registration',
            'patients.search',
            'patients.demographics',
            'patients.medication_safety',
            'appointments.scheduling',
            'appointments.provider_sessions',
            'appointments.referrals',
            'admissions.management',
            'billing.cashier',
            'billing.receipts',
            'billing.invoices',
            'billing.payments',
            'billing.cash_accounts',
            'billing.payment_plans',
            'billing.discounts_refunds',
            'billing.financial_controls',
            'billing.service_catalog',
            'billing.payer_contracts',
            'billing.revenue_cycle',
            'claims.insurance',
            'fiscal_receipts.tra',
            'pos.registers_sessions',
            'pos.sales',
            'pos.lab_quick',
            'pos.cafeteria',
            'pos.pharmacy_otc',
            'staff.directory',
            'staff.profiles',
            'departments.management',
            'clinical.specialties',
            'platform.facility_admin',
            'platform.resource_registry',
            'reports.daily_cash',
            'reports.revenue_cycle',
        ],
        'clinical_operations' => [
            'patients.registration',
            'patients.search',
            'patients.demographics',
            'patients.medication_safety',
            'appointments.scheduling',
            'appointments.provider_sessions',
            'appointments.referrals',
            'admissions.management',
            'emergency.triage',
            'clinical.encounters',
            'clinical.orders',
            'medical_records.core',
            'medical_records.governance',
            'laboratory.orders',
            'radiology.orders',
            'pharmacy.orders',
            'pharmacy.dispensing',
            'theatre.procedures',
            'inpatient.ward',
            'inpatient.tasks',
            'inpatient.care_plans',
            'billing.cashier',
            'billing.receipts',
            'billing.invoices',
            'billing.payments',
            'billing.cash_accounts',
            'billing.payment_plans',
            'billing.discounts_refunds',
            'billing.financial_controls',
            'billing.service_catalog',
            'billing.payer_contracts',
            'billing.revenue_cycle',
            'claims.insurance',
            'fiscal_receipts.tra',
            'pos.registers_sessions',
            'pos.sales',
            'pos.lab_quick',
            'pos.cafeteria',
            'pos.pharmacy_otc',
            'inventory.stock_issue',
            'inventory.items',
            'inventory.stock_movements',
            'inventory.requisitions',
            'inventory.procurement',
            'inventory.suppliers',
            'inventory.warehouses',
            'inventory.transfers',
            'inventory.analytics',
            'staff.directory',
            'staff.profiles',
            'staff.documents',
            'staff.credentialing',
            'staff.privileges',
            'departments.management',
            'clinical.specialties',
            'platform.facility_admin',
            'platform.resource_registry',
            'platform.clinical_catalog',
            'reports.daily_cash',
            'reports.revenue_cycle',
            'reports.operational',
        ],
        'hospital_network' => [
            'patients.registration',
            'patients.search',
            'patients.demographics',
            'patients.medication_safety',
            'appointments.scheduling',
            'appointments.provider_sessions',
            'appointments.referrals',
            'admissions.management',
            'emergency.triage',
            'clinical.encounters',
            'clinical.orders',
            'medical_records.core',
            'medical_records.governance',
            'laboratory.orders',
            'radiology.orders',
            'pharmacy.orders',
            'pharmacy.dispensing',
            'theatre.procedures',
            'inpatient.ward',
            'inpatient.tasks',
            'inpatient.care_plans',
            'billing.cashier',
            'billing.receipts',
            'billing.invoices',
            'billing.payments',
            'billing.cash_accounts',
            'billing.payment_plans',
            'billing.discounts_refunds',
            'billing.financial_controls',
            'billing.service_catalog',
            'billing.payer_contracts',
            'billing.revenue_cycle',
            'claims.insurance',
            'fiscal_receipts.tra',
            'pos.registers_sessions',
            'pos.sales',
            'pos.lab_quick',
            'pos.cafeteria',
            'pos.pharmacy_otc',
            'inventory.stock_issue',
            'inventory.items',
            'inventory.stock_movements',
            'inventory.requisitions',
            'inventory.procurement',
            'inventory.suppliers',
            'inventory.warehouses',
            'inventory.transfers',
            'inventory.analytics',
            'staff.directory',
            'staff.profiles',
            'staff.documents',
            'staff.credentialing',
            'staff.privileges',
            'departments.management',
            'clinical.specialties',
            'platform.facility_admin',
            'platform.resource_registry',
            'platform.clinical_catalog',
            'platform.user_security',
            'platform.rbac',
            'platform.branding',
            'platform.feature_flags',
            'platform.subscription_admin',
            'multi_facility.operations',
            'facility.rollouts',
            'audit.advanced',
            'audit.exports',
            'data_privacy.governance',
            'integrations.interoperability',
            'integrations.health_insurance',
            'integrations.national_reporting',
            'reports.daily_cash',
            'reports.revenue_cycle',
            'reports.operational',
            'reports.executive',
        ],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private array $legacyPlanEntitlements = [
        'patient_registration' => [
            'patients.registration',
            'patients.search',
            'patients.demographics',
            'platform.facility_admin',
        ],
        'front_desk_billing' => [
            'patients.registration',
            'appointments.scheduling',
            'billing.cashier',
            'billing.receipts',
            'reports.daily_cash',
        ],
        'clinical_operations' => [
            'patients.registration',
            'appointments.scheduling',
            'clinical.encounters',
            'clinical.orders',
            'laboratory.orders',
            'pharmacy.dispensing',
            'inventory.stock_issue',
            'reports.operational',
        ],
        'hospital_network' => [
            'patients.registration',
            'clinical.encounters',
            'billing.revenue_cycle',
            'inventory.procurement',
            'multi_facility.operations',
            'audit.advanced',
            'integrations.interoperability',
            'reports.executive',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('platform_subscription_plans') || ! Schema::hasTable('platform_subscription_plan_entitlements')) {
            return;
        }

        $now = now();

        foreach ($this->plans as $code => $attributes) {
            $existing = DB::table('platform_subscription_plans')
                ->where('code', $code)
                ->first(['id', 'metadata']);

            $metadata = $this->mergedPlanMetadata(
                $existing?->metadata ?? null,
                [
                    'pricing_policy' => '2026 Tanzania private-hospital baseline fee. Adjust per bed count, SLA, implementation, integrations, and support contract.',
                    'requires_price_configuration' => false,
                    'market_profile' => 'TZ_PRIVATE_HOSPITAL_2026',
                ],
            );

            if ($existing) {
                DB::table('platform_subscription_plans')
                    ->where('code', $code)
                    ->update([
                        'name' => $attributes['name'],
                        'description' => $attributes['description'],
                        'billing_cycle' => 'monthly',
                        'price_amount' => $attributes['price_amount'],
                        'currency_code' => 'TZS',
                        'status' => 'active',
                        'sort_order' => $attributes['sort_order'],
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table('platform_subscription_plans')->insert([
                'id' => (string) Str::uuid(),
                'code' => $code,
                'name' => $attributes['name'],
                'description' => $attributes['description'],
                'billing_cycle' => 'monthly',
                'price_amount' => $attributes['price_amount'],
                'currency_code' => 'TZS',
                'status' => 'active',
                'sort_order' => $attributes['sort_order'],
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $plans = DB::table('platform_subscription_plans')
            ->whereIn('code', array_keys($this->plans))
            ->get(['id', 'code'])
            ->keyBy('code');

        foreach ($plans as $code => $plan) {
            $enabledKeys = array_flip($this->planEntitlements[(string) $code] ?? []);

            foreach ($this->catalog as $entitlement) {
                $existing = DB::table('platform_subscription_plan_entitlements')
                    ->where('plan_id', $plan->id)
                    ->where('entitlement_key', $entitlement['key'])
                    ->first(['id']);

                $payload = [
                    'entitlement_label' => $entitlement['label'],
                    'entitlement_group' => $entitlement['group'],
                    'entitlement_type' => 'feature',
                    'enabled' => array_key_exists($entitlement['key'], $enabledKeys),
                    'metadata' => json_encode([
                        'route_permissions' => $entitlement['permissions'] ?? [],
                        'catalog_profile' => 'TZ_PRIVATE_HOSPITAL_2026',
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ];

                if ($existing) {
                    DB::table('platform_subscription_plan_entitlements')
                        ->where('id', $existing->id)
                        ->update($payload);

                    continue;
                }

                DB::table('platform_subscription_plan_entitlements')->insert(array_merge($payload, [
                    'id' => (string) Str::uuid(),
                    'plan_id' => $plan->id,
                    'entitlement_key' => $entitlement['key'],
                    'limit_value' => null,
                    'created_at' => $now,
                ]));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_subscription_plans') || ! Schema::hasTable('platform_subscription_plan_entitlements')) {
            return;
        }

        $legacyKeys = [
            'patients.registration',
            'patients.search',
            'patients.demographics',
            'appointments.scheduling',
            'billing.cashier',
            'billing.receipts',
            'billing.revenue_cycle',
            'clinical.encounters',
            'clinical.orders',
            'laboratory.orders',
            'pharmacy.dispensing',
            'inventory.stock_issue',
            'inventory.procurement',
            'platform.facility_admin',
            'multi_facility.operations',
            'audit.advanced',
            'integrations.interoperability',
            'reports.daily_cash',
            'reports.operational',
            'reports.executive',
        ];

        $planIds = DB::table('platform_subscription_plans')
            ->whereIn('code', array_keys($this->plans))
            ->pluck('id')
            ->all();

        if ($planIds === []) {
            return;
        }

        DB::table('platform_subscription_plan_entitlements')
            ->whereIn('plan_id', $planIds)
            ->whereNotIn('entitlement_key', $legacyKeys)
            ->delete();

        $now = now();
        $catalogByKey = collect($this->catalog)
            ->keyBy('key')
            ->all();
        $plans = DB::table('platform_subscription_plans')
            ->whereIn('code', array_keys($this->legacyPlans))
            ->get(['id', 'code'])
            ->keyBy('code');

        foreach ($this->legacyPlans as $code => $attributes) {
            DB::table('platform_subscription_plans')
                ->where('code', $code)
                ->update([
                    'name' => $attributes['name'],
                    'description' => $attributes['description'],
                    'price_amount' => $attributes['price_amount'],
                    'currency_code' => 'TZS',
                    'billing_cycle' => 'monthly',
                    'sort_order' => $attributes['sort_order'],
                    'metadata' => json_encode([
                        'pricing_policy' => 'Starter monthly testing fee. Edit before final commercial billing.',
                        'requires_price_configuration' => false,
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ]);

            $plan = $plans[$code] ?? null;
            if (! $plan) {
                continue;
            }

            $enabledKeys = array_flip($this->legacyPlanEntitlements[$code] ?? []);
            foreach ($legacyKeys as $entitlementKey) {
                $catalog = $catalogByKey[$entitlementKey] ?? null;
                DB::table('platform_subscription_plan_entitlements')
                    ->where('plan_id', $plan->id)
                    ->where('entitlement_key', $entitlementKey)
                    ->update([
                        'entitlement_label' => $catalog['label'] ?? $entitlementKey,
                        'entitlement_group' => $catalog['group'] ?? null,
                        'enabled' => array_key_exists($entitlementKey, $enabledKeys),
                        'metadata' => null,
                        'updated_at' => $now,
                    ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergedPlanMetadata(mixed $metadata, array $overrides): array
    {
        $decoded = is_string($metadata) && trim($metadata) !== ''
            ? json_decode($metadata, true)
            : [];

        if (! is_array($decoded)) {
            $decoded = [];
        }

        return array_merge($decoded, $overrides);
    }
};
