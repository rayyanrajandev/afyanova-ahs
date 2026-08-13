<?php

use App\Modules\Platform\Application\Services\DashboardWorkflowRegistry;
use App\Modules\Platform\Application\Services\ModuleRegistryService;
use App\Modules\Platform\Domain\ValueObjects\DashboardSessionContext;

// DashboardWorkflowRegistry now reads its workflow catalog from config/modules.php via
// ModuleRegistryService, so this "unit" test needs the app container booted (for the
// config() helper) even though it doesn't touch the database.
uses(Tests\TestCase::class);

it('prioritizes operations for HR role', function (): void {
    $registry = new DashboardWorkflowRegistry(new ModuleRegistryService);

    $context = new DashboardSessionContext(
        roleCodesUpper: ['ADMIN.HR'],
        permissionNames: [
            'staff.read',
            'staff.credentialing.read',
            'specialties.read',
        ],
        isFacilitySuperAdmin: false,
        isPlatformSuperAdmin: false,
    );

    expect($registry->eligibleWorkflowKeys($context))->toContain('operations')
        ->and($registry->defaultWorkflowKey($context))->toBe('operations');
});

it('prioritizes records for medical records officer without clinical roles', function (): void {
    $registry = new DashboardWorkflowRegistry(new ModuleRegistryService);

    $context = new DashboardSessionContext(
        roleCodesUpper: ['ADMIN.MEDICAL.RECORDS'],
        permissionNames: [
            'patients.read',
            'medical.records.read',
            'medical.records.archive',
        ],
        isFacilitySuperAdmin: false,
        isPlatformSuperAdmin: false,
    );

    expect($registry->eligibleWorkflowKeys($context))->toContain('records')
        ->and($registry->defaultWorkflowKey($context))->toBe('records');
});

it('does not add records workflow for nursing role with medical records read', function (): void {
    $registry = new DashboardWorkflowRegistry(new ModuleRegistryService);

    $context = new DashboardSessionContext(
        roleCodesUpper: ['CLINICAL.NURSE'],
        permissionNames: [
            'medical.records.read',
            'inpatient.ward.read',
        ],
        isFacilitySuperAdmin: false,
        isPlatformSuperAdmin: false,
    );

    expect($registry->eligibleWorkflowKeys($context))->not->toContain('records')
        ->and($registry->eligibleWorkflowKeys($context))->toContain('nursing');
});

it('adds supply workflow from inventory procurement permission', function (): void {
    $registry = new DashboardWorkflowRegistry(new ModuleRegistryService);

    $context = new DashboardSessionContext(
        roleCodesUpper: ['INVENTORY.STAFF'],
        permissionNames: ['inventory.procurement.read'],
        isFacilitySuperAdmin: false,
        isPlatformSuperAdmin: false,
    );

    expect($registry->eligibleWorkflowKeys($context))->toContain('supply')
        ->and($registry->defaultWorkflowKey($context))->toBe('supply');
});

it('filters workflows when facility subscription entitlements are missing', function (): void {
    $registry = new DashboardWorkflowRegistry(new ModuleRegistryService);

    $keys = $registry->filterWorkflowKeysByFacilitySubscription(
        ['operations', 'supply', 'front_desk'],
        ['patients.search', 'appointments.scheduling'],
        false,
    );

    expect($keys)->toBe(['front_desk']);
});

it('includes permission-gated widgets in workflow definitions', function (): void {
    $registry = new DashboardWorkflowRegistry(new ModuleRegistryService);

    $context = new DashboardSessionContext(
        roleCodesUpper: ['ADMIN.HR'],
        permissionNames: ['staff.read', 'staff.credentialing.read'],
        isFacilitySuperAdmin: false,
        isPlatformSuperAdmin: false,
    );

    $operations = collect($registry->eligibleWorkflowDefinitions($context))
        ->firstWhere('key', 'operations');

    expect($operations)->not->toBeNull()
        ->and($operations['widgets'])->not->toBeEmpty()
        ->and(collect($operations['widgets'])->pluck('id')->all())->toContain('credentialing');
});

it('prioritizes laboratory dashboard for lab user and excludes supply chain', function (): void {
    $registry = new DashboardWorkflowRegistry(new ModuleRegistryService);

    $context = new DashboardSessionContext(
        roleCodesUpper: ['LAB.STAFF'],
        permissionNames: [
            'laboratory.orders.read',
            'laboratory.orders.update-status',
            'inventory.procurement.read',
            'inventory.procurement.create-request',
        ],
        isFacilitySuperAdmin: false,
        isPlatformSuperAdmin: false,
    );

    expect($registry->eligibleWorkflowKeys($context))->toContain('direct_service')
        ->and($registry->eligibleWorkflowKeys($context))->not->toContain('supply')
        ->and($registry->defaultWorkflowKey($context))->toBe('direct_service');

    $directService = collect($registry->eligibleWorkflowDefinitions($context))
        ->firstWhere('key', 'direct_service');

    expect($directService)->not->toBeNull()
        ->and($directService['label'])->toBe('Laboratory');
});

it('prioritizes clinician dashboard and excludes front desk and supply chain noise', function (): void {
    $registry = new DashboardWorkflowRegistry(new ModuleRegistryService);

    $clinicalPermissions = [
        'patients.read',
        'patients.update',
        'admissions.read',
        'appointments.read',
        'medical.records.read',
        'medical.records.create',
        'medical.records.update',
        'medical.records.finalize',
        'medical.records.amend',
        'medical.records.attest',
        'inpatient.ward.read',
        'inpatient.ward.create-round-note',
        'inpatient.ward.view-audit-logs',
        'inventory.procurement.read',
        'inventory.procurement.create-request',
        'staff.clinical-directory.read',
    ];

    $orderingPermissions = [
        'laboratory.orders.create',
        'laboratory.orders.read',
        'pharmacy.orders.create',
        'pharmacy.orders.read',
        'radiology.orders.read',
        'radiology.orders.create',
        'theatre.procedures.read',
        'theatre.procedures.create',
        'platform.clinical-catalog.read',
    ];

    $context = new DashboardSessionContext(
        roleCodesUpper: ['CLINICAL.GENERAL', 'CLINICAL.PHYSICIAN'],
        permissionNames: array_values(array_unique(array_merge($clinicalPermissions, $orderingPermissions))),
        isFacilitySuperAdmin: false,
        isPlatformSuperAdmin: false,
    );

    expect($registry->eligibleWorkflowKeys($context))->toBe([
        'clinician',
        'nursing',
        'theatre',
        'direct_service',
    ])
        ->and($registry->defaultWorkflowKey($context))->toBe('clinician');
});

it('does not add supply workflow for nursing role with procurement requisition permission', function (): void {
    $registry = new DashboardWorkflowRegistry(new ModuleRegistryService);

    $context = new DashboardSessionContext(
        roleCodesUpper: ['CLINICAL.NURSE'],
        permissionNames: [
            'inpatient.ward.read',
            'inventory.procurement.read',
            'inventory.procurement.create-request',
        ],
        isFacilitySuperAdmin: false,
        isPlatformSuperAdmin: false,
    );

    expect($registry->eligibleWorkflowKeys($context))->toContain('nursing')
        ->and($registry->eligibleWorkflowKeys($context))->not->toContain('supply');
});
