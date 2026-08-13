<?php

use App\Modules\Platform\Application\Services\PlanCatalogService;

uses(Tests\TestCase::class);

it('exposes a catalog version', function (): void {
    $service = app(PlanCatalogService::class);

    expect($service->version())->not->toBe('dev');
});

it('resolves patient_registration entitlements from capabilities minus exclusions plus additive grants', function (): void {
    $service = app(PlanCatalogService::class);

    $keys = $service->entitlementsForPlan('patient_registration');

    // Capability-assigned features present.
    expect($keys)->toContain('patients.registration')
        ->toContain('patients.search')
        ->toContain('appointments.scheduling')
        ->toContain('billing.cashier')
        ->toContain('pos.sales')
        ->toContain('staff.directory')
        ->toContain('platform.facility_admin');

    // Front-office extras excluded for this plan (matches existing matrix).
    expect($keys)->not->toContain('appointments.provider_sessions')
        ->not->toContain('appointments.referrals')
        ->not->toContain('admissions.management');

    // Billing/revenue features excluded for this plan.
    expect($keys)->not->toContain('billing.payment_plans')
        ->not->toContain('claims.insurance')
        ->not->toContain('billing.financial_controls');

    // Additive platform-wide grants present.
    expect($keys)->toContain('clinical.walk_in_queue')
        ->toContain('clinical_procedure.orders');
});

it('resolves clinical_operations entitlements across care capabilities', function (): void {
    $service = app(PlanCatalogService::class);

    $keys = $service->entitlementsForPlan('clinical_operations');

    expect($keys)->toContain('emergency.triage')
        ->toContain('clinical.encounters')
        ->toContain('laboratory.orders')
        ->toContain('radiology.orders')
        ->toContain('pharmacy.orders')
        ->toContain('inpatient.ward')
        ->toContain('inventory.procurement')
        ->toContain('staff.credentialing');

    // Enterprise-only capabilities excluded.
    expect($keys)->not->toContain('platform.subscription_admin')
        ->not->toContain('audit.advanced')
        ->not->toContain('integrations.interoperability')
        ->not->toContain('reports.executive');
});

it('resolves hospital_network entitlements including platform administration', function (): void {
    $service = app(PlanCatalogService::class);

    $keys = $service->entitlementsForPlan('hospital_network');

    expect($keys)->toContain('medical_records.governance')
        ->toContain('multi_facility.operations')
        ->toContain('platform.rbac')
        ->toContain('audit.advanced')
        ->toContain('data_privacy.governance')
        ->toContain('integrations.health_insurance')
        ->toContain('reports.executive');
});

it('returns no missing catalog keys for all defined plans', function (): void {
    $service = app(PlanCatalogService::class);

    expect($service->missingCatalogKeys())->toBe([]);
});

it('resolves plan quota limits merged from capability defaults and plan overrides', function (): void {
    $service = app(PlanCatalogService::class);

    $limits = $service->limitsForPlan('patient_registration');

    expect($limits['patients.monthly'])->toBe(1000)
        ->and($limits['staff.seats'])->toBe(5)
        ->and($limits['inventory.items.max'])->toBe(100)
        // Capability default is null (unlimited) and no plan override.
        ->and($limits['billing.transactions.monthly'])->toBeNull();
});

it('treats hospital_network quota limits as unlimited', function (): void {
    $service = app(PlanCatalogService::class);

    $limits = $service->limitsForPlan('hospital_network');

    expect($limits['patients.monthly'])->toBeNull()
        ->and($limits['staff.seats'])->toBeNull()
        ->and($limits['inventory.items.max'])->toBeNull();
});

it('builds a fast entitlement index for a plan', function (): void {
    $service = app(PlanCatalogService::class);

    $index = $service->entitlementIndexForPlan('clinical_operations');

    expect($index['laboratory.orders'])->toBeTrue()
        ->and($index['platform.rbac'] ?? null)->toBeNull();
});

it('exposes feature metadata with permissions by entitlement key', function (): void {
    $service = app(PlanCatalogService::class);

    $feature = $service->feature('pharmacy.orders');

    expect($feature)->not->toBeNull()
        ->and($feature['capability'])->toBe('pharmacy')
        ->and($feature['permissions'])->toContain('pharmacy.orders.read');

    expect($service->feature('does.not.exist'))->toBeNull();
});

it('exposes dependency map for features', function (): void {
    $service = app(PlanCatalogService::class);

    $dependencies = $service->dependencies();

    expect($dependencies['pos.sales'])->toContain('pos.registers_sessions')
        ->and($dependencies['medical_records.governance'])->toContain('medical_records.core');
});

// ---------------------------------------------------------------------------
// Drift guards: the route_entitlements map must reference only keys that exist
// in the catalog, and must cover every legacy SPECIAL_ENTITLEMENT_MAP entry so
// the config-driven middleware never silently drops a route gate.
// ---------------------------------------------------------------------------

it('route entitlement map references only catalog-defined entitlement keys', function (): void {
    $service = app(PlanCatalogService::class);
    $defined = array_fill_keys($service->allEntitlementKeys(), true);

    $routeMap = (array) config('plan_catalog.route_entitlements', []);

    expect($routeMap)->not->toBeEmpty();

    $missing = [];
    foreach ($routeMap as $entitlements) {
        foreach ((array) $entitlements as $entitlement) {
            if (! isset($defined[$entitlement])) {
                $missing[] = $entitlement;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('route entitlement map covers every legacy SPECIAL_ENTITLEMENT_MAP entry', function (): void {
    $routeMap = (array) config('plan_catalog.route_entitlements', []);

    // The legacy map lives in EnsureMappedFacilitySubscriptionEntitlement.
    // Any legacy route pattern must also exist in the catalog map so the
    // config is a strict superset (no route gates lost in migration).
    $legacyMap = [
        'appointments.start-consultation',
        'appointments.manage-provider-session',
        'appointments.referrals.',
        'appointments.',
        'admissions.status-counts',
        'admissions.discharge-destination-options',
        'admissions.index',
        'admissions.',
        'medical-records.signer-attestations.',
        'medical-records.versions.',
        'medical-records.audit-logs.',
        'medical-records.audit-logs',
        'medical-records.',
        'encounters.audit-logs.',
        'encounters.audit-logs',
        'encounters.',
        'emergency-triage-cases.',
        'service-requests.',
        'inpatient-ward.tasks.',
        'inpatient-ward.round-notes.',
        'inpatient-ward.care-plans.',
        'inpatient-ward.discharge-checklists.',
        'inpatient-ward.task-status-counts',
        'inpatient-ward.care-plan-status-counts',
        'inpatient-ward.discharge-checklist-status-counts',
        'billing-invoices.financial-controls.',
        'billing-invoices.record-payment',
        'billing-invoices.reverse-payment',
        'billing-invoices.payments',
        'billing-invoices.audit-logs.',
        'billing-invoices.audit-logs',
        'billing-payment-plans.',
        'billing-corporate-accounts.',
        'billing-corporate-runs.',
        'billing-service-catalog.',
        'consultation-mappings.',
        'billing-payer-contracts.',
        'cash-billing.',
        'discounts.',
        'billing-refunds.',
        'billing-routing.',
        'pos.registers.',
        'pos.sessions.',
        'pos.cafeteria.',
        'pos.pharmacy-otc.',
        'pos.lab-quick.',
        'pos.sales.',
        'inventory-procurement.suppliers.',
        'inventory-procurement.warehouses.',
        'inventory-procurement.warehouse-transfers.',
        'inventory-procurement.analytics.',
        'inventory-procurement.stock-movements.',
        'inventory-procurement.department-requisitions.',
        'inventory-procurement.department-stock.',
        'inventory-procurement.shortage-queue.',
        'inventory-procurement.procurement-requests.',
        'inventory-procurement.msd-orders.',
        'inventory-procurement.supplier-lead-times.',
        'inventory-procurement.items.',
        'inventory-procurement.batches.',
        'inventory-procurement.reference-data.',
        'inventory-procurement.barcode-lookup',
        'inventory-procurement.',
        'staff.credentialing.',
        'staff.credentialing-alerts',
        'staff.documents.',
        'staff.privileges.',
        'staff.clinical-directory.',
        'staff.specialties.',
        'staff.',
        'privilege-catalogs.',
        'specialties.',
        'departments.',
    ];

    $missing = array_values(array_diff($legacyMap, array_keys($routeMap)));

    expect($missing)->toBe([]);
});
