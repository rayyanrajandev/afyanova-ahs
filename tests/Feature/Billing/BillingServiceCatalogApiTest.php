<?php

use App\Models\User;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemAuditLogModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function billingServiceCatalogPayload(array $overrides = []): array
{
    return array_merge([
        'serviceCode' => 'CONSULT-OPD-001',
        'serviceName' => 'OPD Consultation',
        'serviceType' => 'consultation',
        'department' => 'General OPD',
        'unit' => 'service',
        'basePrice' => 25000,
        'currencyCode' => 'tzs',
        'taxRatePercent' => 0,
        'isTaxable' => false,
        'effectiveFrom' => now()->startOfDay()->toDateTimeString(),
        'description' => 'Initial outpatient consultation fee',
        'metadata' => [
            'payerCategory' => 'self_pay',
        ],
    ], $overrides);
}

function makeBillingServiceCatalogUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function createBillingCatalogDepartment(array $overrides = []): array
{
    $department = array_merge([
        'id' => (string) Str::uuid(),
        'tenant_id' => null,
        'facility_id' => null,
        'code' => 'OPD',
        'name' => 'General OPD',
        'service_type' => 'consultation',
        'manager_user_id' => null,
        'status' => 'active',
        'status_reason' => null,
        'description' => 'Test department',
        'is_patient_facing' => true,
        'is_appointmentable' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('departments')->insert($department);

    return $department;
}

function createClinicalCatalogItem(array $overrides = []): array
{
    $item = array_merge([
        'id' => (string) Str::uuid(),
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'service',
        'code' => 'CLIN-CATALOG-001',
        'name' => 'Clinical Definition',
        'department_id' => null,
        'category' => null,
        'unit' => 'service',
        'description' => 'Clinical catalog test item',
        'metadata' => json_encode([
            'billingServiceCode' => 'CONSULT-OPD-001',
        ], JSON_THROW_ON_ERROR),
        'status' => 'active',
        'status_reason' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    if (is_array($item['metadata'] ?? null)) {
        $item['metadata'] = json_encode($item['metadata'], JSON_THROW_ON_ERROR);
    }

    DB::table('platform_clinical_catalog_items')->insert($item);

    $item['metadata'] = json_decode((string) $item['metadata'], true, 512, JSON_THROW_ON_ERROR);

    return $item;
}

function createBillingServiceCatalogItem(User $user, array $payload = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/billing-service-catalog/items', billingServiceCatalogPayload($payload))
        ->assertCreated()
        ->json('data');
}

function createBillingServiceCatalogRevision(User $user, string $itemId, array $payload = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/billing-service-catalog/items/'.$itemId.'/revisions', array_merge([
            'basePrice' => 30000,
            'effectiveFrom' => now()->addDay()->toDateTimeString(),
        ], $payload))
        ->assertCreated()
        ->json('data');
}

function billingServiceCatalogImpactPayerContractPayload(array $overrides = []): array
{
    return array_merge([
        'contractCode' => 'NHIF-IMAGING-2026',
        'contractName' => 'NHIF Imaging 2026',
        'payerType' => 'insurance',
        'payerName' => 'NHIF',
        'payerPlanCode' => 'IMG',
        'payerPlanName' => 'Imaging',
        'currencyCode' => 'tzs',
        'defaultCoveragePercent' => 80,
        'defaultCopayType' => 'percentage',
        'defaultCopayValue' => 20,
        'requiresPreAuthorization' => true,
        'claimSubmissionDeadlineDays' => 30,
        'settlementCycleDays' => 45,
        'effectiveFrom' => now()->subDay()->toDateTimeString(),
        'termsAndNotes' => 'Imaging contract terms',
        'metadata' => [
            'contractClass' => 'imaging',
        ],
    ], $overrides);
}

function billingServiceCatalogImpactAuthorizationRulePayload(array $overrides = []): array
{
    return array_merge([
        'ruleCode' => 'AUTH-IMG-001',
        'ruleName' => 'Imaging needs authorization',
        'serviceCode' => 'IMG-PACT-001',
        'serviceType' => 'radiology',
        'department' => 'Radiology',
        'priority' => 'routine',
        'amountThreshold' => 0,
        'quantityLimit' => 1,
        'requiresAuthorization' => true,
        'autoApprove' => false,
        'authorizationValidityDays' => 14,
        'ruleNotes' => 'Authorization is required.',
        'metadata' => [
            'reviewOwner' => 'payer-team',
        ],
    ], $overrides);
}

function createBillingServiceCatalogImpactPayerContract(User $user, array $payload = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts', billingServiceCatalogImpactPayerContractPayload($payload))
        ->assertCreated()
        ->json('data');
}

it('requires authentication for billing service catalog item creation', function (): void {
    $this->postJson('/api/v1/billing-service-catalog/items', billingServiceCatalogPayload())
        ->assertUnauthorized();
});

it('forbids billing service catalog list without read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items')
        ->assertForbidden();
});

it('creates billing service catalog item with normalized code and currency', function (): void {
    $user = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-service-catalog/items', billingServiceCatalogPayload())
        ->assertCreated()
        ->assertJsonPath('data.serviceCode', 'CONSULT-OPD-001')
        ->assertJsonPath('data.currencyCode', 'TZS')
        ->assertJsonPath('data.status', 'active');
});

it('maps a selected department id to the live department record', function (): void {
    $user = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);
    $department = createBillingCatalogDepartment([
        'code' => 'RAD',
        'name' => 'Radiology',
        'service_type' => 'radiology',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-service-catalog/items', billingServiceCatalogPayload([
            'serviceCode' => 'RAD-DEPT-001',
            'serviceName' => 'Radiology Service',
            'serviceType' => 'radiology',
            'departmentId' => $department['id'],
            'department' => 'Manual text should not win',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.departmentId', $department['id'])
        ->assertJsonPath('data.department', 'Radiology');
});

it('auto-links a billing price to a matching clinical catalog definition', function (): void {
    $user = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);
    $clinicalItem = createClinicalCatalogItem([
        'catalog_type' => 'lab_test',
        'code' => 'LAB-CBC',
        'name' => 'Complete Blood Count',
        'metadata' => [
            'billingServiceCode' => 'LAB-CBC-001',
        ],
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-service-catalog/items', billingServiceCatalogPayload([
            'serviceCode' => 'LAB-CBC-001',
            'serviceName' => 'CBC Price',
            'serviceType' => 'laboratory',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.clinicalCatalogItemId', $clinicalItem['id'])
        ->assertJsonPath('data.clinicalCatalogItem.id', $clinicalItem['id'])
        ->assertJsonPath('data.clinicalCatalogItem.catalogType', 'lab_test')
        ->assertJsonPath('data.clinicalCatalogItem.code', 'LAB-CBC')
        ->assertJsonPath('data.clinicalCatalogItem.name', 'Complete Blood Count');
});

it('rejects duplicate billing service catalog service code in same scope', function (): void {
    $user = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);
    createBillingServiceCatalogItem($user);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-service-catalog/items', billingServiceCatalogPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['serviceCode']);
});

it('lists and filters billing service catalog items', function (): void {
    $user = makeBillingServiceCatalogUser([
        'billing.service-catalog.manage',
        'billing.service-catalog.read',
    ]);
    $labDepartment = createBillingCatalogDepartment([
        'code' => 'LAB',
        'name' => 'Laboratory',
        'service_type' => 'laboratory',
    ]);

    createBillingServiceCatalogItem($user, [
        'serviceCode' => 'CONSULT-OPD-002',
        'serviceName' => 'Follow-up Consultation',
        'serviceType' => 'consultation',
    ]);
    createBillingServiceCatalogItem($user, [
        'serviceCode' => 'LAB-CBC-001',
        'serviceName' => 'Complete Blood Count',
        'serviceType' => 'laboratory',
        'departmentId' => $labDepartment['id'],
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items?q=cbc&serviceType=laboratory')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.serviceCode', 'LAB-CBC-001');

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items?departmentId='.$labDepartment['id'])
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.departmentId', $labDepartment['id']);
});

it('filters billing service catalog items by tariff lifecycle window', function (): void {
    $user = makeBillingServiceCatalogUser([
        'billing.service-catalog.manage',
        'billing.service-catalog.read',
    ]);

    createBillingServiceCatalogItem($user, [
        'serviceCode' => 'CONSULT-LIVE-001',
        'serviceName' => 'Live Consultation',
        'effectiveFrom' => now()->subDay()->toDateTimeString(),
        'effectiveTo' => now()->addDay()->toDateTimeString(),
    ]);
    createBillingServiceCatalogItem($user, [
        'serviceCode' => 'CONSULT-FUTURE-001',
        'serviceName' => 'Future Consultation',
        'effectiveFrom' => now()->addDays(3)->toDateTimeString(),
    ]);
    createBillingServiceCatalogItem($user, [
        'serviceCode' => 'CONSULT-EXPIRED-001',
        'serviceName' => 'Expired Consultation',
        'effectiveFrom' => now()->subDays(5)->toDateTimeString(),
        'effectiveTo' => now()->subDay()->toDateTimeString(),
    ]);
    createBillingServiceCatalogItem($user, [
        'serviceCode' => 'CONSULT-NOWINDOW-001',
        'serviceName' => 'Open Consultation',
        'effectiveFrom' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items?lifecycle=scheduled')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.serviceCode', 'CONSULT-FUTURE-001');

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items?lifecycle=expired')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.serviceCode', 'CONSULT-EXPIRED-001');

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items?lifecycle=no_window')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.serviceCode', 'CONSULT-NOWINDOW-001');
});

it('returns billing service catalog status counts', function (): void {
    $user = makeBillingServiceCatalogUser([
        'billing.service-catalog.manage',
        'billing.service-catalog.read',
    ]);

    $active = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'WARD-BED-001',
        'serviceName' => 'Ward Bed Day Charge',
        'serviceType' => 'admission',
    ]);
    createBillingServiceCatalogItem($user, [
        'serviceCode' => 'PHARM-RX-001',
        'serviceName' => 'Prescription Dispense Fee',
        'serviceType' => 'pharmacy',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-service-catalog/items/'.$active['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Tariff review cycle',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items/status-counts')
        ->assertOk()
        ->assertJsonPath('data.active', 1)
        ->assertJsonPath('data.inactive', 1)
        ->assertJsonPath('data.retired', 0)
        ->assertJsonPath('data.total', 2);
});

it('returns billing service catalog status counts for a lifecycle-scoped queue', function (): void {
    $user = makeBillingServiceCatalogUser([
        'billing.service-catalog.manage',
        'billing.service-catalog.read',
    ]);

    createBillingServiceCatalogItem($user, [
        'serviceCode' => 'LAB-SCHEDULED-001',
        'serviceName' => 'Scheduled Lab Tariff',
        'effectiveFrom' => now()->addDay()->toDateTimeString(),
    ]);
    $inactive = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'LAB-SCHEDULED-002',
        'serviceName' => 'Scheduled Inactive Lab Tariff',
        'effectiveFrom' => now()->addDays(2)->toDateTimeString(),
    ]);
    createBillingServiceCatalogItem($user, [
        'serviceCode' => 'LAB-LIVE-001',
        'serviceName' => 'Live Lab Tariff',
        'effectiveFrom' => now()->subDay()->toDateTimeString(),
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-service-catalog/items/'.$inactive['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Pending review before go-live',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items/status-counts?lifecycle=scheduled')
        ->assertOk()
        ->assertJsonPath('data.active', 1)
        ->assertJsonPath('data.inactive', 1)
        ->assertJsonPath('data.total', 2);
});

it('updates billing service catalog item fields', function (): void {
    $user = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);
    $created = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'RAD-XRAY-001',
        'serviceName' => 'Chest X-Ray',
        'serviceType' => 'radiology',
        'basePrice' => 45000,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-service-catalog/items/'.$created['id'], [
            'serviceName' => 'Chest X-Ray AP',
            'basePrice' => 50000,
            'currencyCode' => 'usd',
            'isTaxable' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.serviceName', 'Chest X-Ray AP')
        ->assertJsonPath('data.basePrice', '50000.00')
        ->assertJsonPath('data.currencyCode', 'USD')
        ->assertJsonPath('data.isTaxable', true);
});

it('allows service identity updates with the identity permission only', function (): void {
    $admin = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);
    $radiologyDepartment = createBillingCatalogDepartment([
        'code' => 'RAD',
        'name' => 'Radiology',
        'service_type' => 'radiology',
    ]);
    $created = createBillingServiceCatalogItem($admin, [
        'serviceCode' => 'RAD-IDENT-001',
        'serviceName' => 'Abdominal Ultrasound',
        'serviceType' => 'radiology',
    ]);

    $identityUser = makeBillingServiceCatalogUser(['billing.service-catalog.manage-identity']);

    $this->actingAs($identityUser)
        ->patchJson('/api/v1/billing-service-catalog/items/'.$created['id'], [
            'serviceName' => 'Abdominal Ultrasound Scan',
            'departmentId' => $radiologyDepartment['id'],
        ])
        ->assertOk()
        ->assertJsonPath('data.serviceName', 'Abdominal Ultrasound Scan')
        ->assertJsonPath('data.departmentId', $radiologyDepartment['id'])
        ->assertJsonPath('data.department', 'Radiology');
});

it('rejects pricing changes when the user only has service identity permission', function (): void {
    $admin = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);
    $created = createBillingServiceCatalogItem($admin, [
        'serviceCode' => 'RAD-IDENT-002',
        'serviceName' => 'Pelvic Ultrasound',
        'basePrice' => 65000,
    ]);

    $identityUser = makeBillingServiceCatalogUser(['billing.service-catalog.manage-identity']);

    $this->actingAs($identityUser)
        ->patchJson('/api/v1/billing-service-catalog/items/'.$created['id'], [
            'basePrice' => 70000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['basePrice']);
});

it('allows pricing changes with the pricing permission only', function (): void {
    $admin = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);
    $created = createBillingServiceCatalogItem($admin, [
        'serviceCode' => 'RAD-PRICE-001',
        'serviceName' => 'CT Scan Abdomen',
        'basePrice' => 125000,
    ]);

    $pricingUser = makeBillingServiceCatalogUser(['billing.service-catalog.manage-pricing']);

    $this->actingAs($pricingUser)
        ->patchJson('/api/v1/billing-service-catalog/items/'.$created['id'], [
            'basePrice' => 130000,
            'currencyCode' => 'tzs',
        ])
        ->assertOk()
        ->assertJsonPath('data.basePrice', '130000.00')
        ->assertJsonPath('data.currencyCode', 'TZS');
});

it('rejects service identity changes when the user only has pricing permission', function (): void {
    $admin = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);
    $created = createBillingServiceCatalogItem($admin, [
        'serviceCode' => 'RAD-PRICE-002',
        'serviceName' => 'MRI Brain',
    ]);

    $pricingUser = makeBillingServiceCatalogUser(['billing.service-catalog.manage-pricing']);

    $this->actingAs($pricingUser)
        ->patchJson('/api/v1/billing-service-catalog/items/'.$created['id'], [
            'serviceName' => 'MRI Brain With Contrast',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['serviceName']);
});

it('creates a new tariff revision without changing the service code family', function (): void {
    $user = makeBillingServiceCatalogUser([
        'billing.service-catalog.manage',
        'billing.service-catalog.read',
    ]);
    $created = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'RAD-USA-REV-001',
        'serviceName' => 'Ultrasound Abdomen',
        'basePrice' => 60000,
        'effectiveFrom' => now()->subDays(10)->toDateTimeString(),
    ]);

    $revision = createBillingServiceCatalogRevision($user, $created['id'], [
        'basePrice' => 75000,
        'effectiveFrom' => now()->addDays(2)->toDateTimeString(),
        'description' => 'Annual revision',
    ]);

    expect($revision['serviceCode'])->toBe('RAD-USA-REV-001');
    expect($revision['versionNumber'])->toBe(2);
    expect($revision['supersedesBillingServiceCatalogItemId'])->toBe($created['id']);

    $source = $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items/'.$created['id'])
        ->assertOk()
        ->json('data');

    expect($source['effectiveTo'])->not->toBeNull();
});

it('keeps the linked clinical catalog definition on a new tariff revision', function (): void {
    $user = makeBillingServiceCatalogUser([
        'billing.service-catalog.manage',
        'billing.service-catalog.read',
    ]);
    $clinicalItem = createClinicalCatalogItem([
        'catalog_type' => 'imaging',
        'code' => 'IMG-US-ABD',
        'name' => 'Ultrasound Abdomen',
        'metadata' => [
            'billingServiceCode' => 'RAD-USA-CLIN-001',
        ],
    ]);

    $created = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'RAD-USA-CLIN-001',
        'serviceName' => 'Ultrasound Abdomen',
        'serviceType' => 'radiology',
        'basePrice' => 60000,
        'effectiveFrom' => now()->subDays(10)->toDateTimeString(),
    ]);

    expect($created['clinicalCatalogItemId'])->toBe($clinicalItem['id']);

    $revision = createBillingServiceCatalogRevision($user, $created['id'], [
        'basePrice' => 72000,
        'effectiveFrom' => now()->addDays(3)->toDateTimeString(),
    ]);

    expect($revision['clinicalCatalogItemId'])->toBe($clinicalItem['id']);
    expect($revision['clinicalCatalogItem']['id'])->toBe($clinicalItem['id']);
});

it('lists tariff version history for a service code family', function (): void {
    $user = makeBillingServiceCatalogUser([
        'billing.service-catalog.manage',
        'billing.service-catalog.read',
    ]);

    $created = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'IMG-HISTORY-001',
        'serviceName' => 'Ultrasound History',
        'basePrice' => 50000,
        'effectiveFrom' => now()->subDays(10)->toDateTimeString(),
    ]);

    createBillingServiceCatalogRevision($user, $created['id'], [
        'basePrice' => 65000,
        'effectiveFrom' => now()->addDays(2)->toDateTimeString(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items/'.$created['id'].'/versions')
        ->assertOk()
        ->assertJsonPath('data.0.versionNumber', 2)
        ->assertJsonPath('data.1.versionNumber', 1);
});

it('returns payer contract impact summary for a service catalog tariff family', function (): void {
    $user = makeBillingServiceCatalogUser([
        'billing.service-catalog.manage',
        'billing.service-catalog.read',
        'billing.payer-contracts.manage',
        'billing.payer-contracts.manage-authorization-rules',
    ]);

    $item = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'IMG-PACT-001',
        'serviceName' => 'Imaging Payer Impact',
        'serviceType' => 'radiology',
        'department' => 'Radiology',
        'currencyCode' => 'TZS',
    ]);

    $contractA = createBillingServiceCatalogImpactPayerContract($user, [
        'contractCode' => 'NHIF-PACT-001',
        'contractName' => 'NHIF Payer Impact',
        'defaultCoveragePercent' => 80,
        'requiresPreAuthorization' => true,
    ]);

    $contractB = createBillingServiceCatalogImpactPayerContract($user, [
        'contractCode' => 'JUBILEE-PACT-001',
        'contractName' => 'Jubilee Payer Impact',
        'payerName' => 'Jubilee',
        'defaultCoveragePercent' => 90,
        'requiresPreAuthorization' => false,
    ]);

    createBillingServiceCatalogImpactPayerContract($user, [
        'contractCode' => 'USD-PACT-001',
        'contractName' => 'USD Contract',
        'currencyCode' => 'USD',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contractA['id'].'/authorization-rules', billingServiceCatalogImpactAuthorizationRulePayload([
            'ruleCode' => 'AUTH-PACT-SVC-001',
            'serviceCode' => 'IMG-PACT-001',
            'serviceType' => null,
            'department' => null,
            'autoApprove' => false,
        ]))
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contractB['id'].'/authorization-rules', billingServiceCatalogImpactAuthorizationRulePayload([
            'ruleCode' => 'AUTH-PACT-TYPE-001',
            'serviceCode' => null,
            'serviceType' => 'radiology',
            'department' => null,
            'autoApprove' => true,
        ]))
        ->assertCreated();

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items/'.$item['id'].'/payer-impact')
        ->assertOk()
        ->assertJsonPath('data.activeContractCount', 2)
        ->assertJsonPath('data.preAuthorizationContractCount', 1)
        ->assertJsonPath('data.contractsWithMatchingRulesCount', 2)
        ->assertJsonPath('data.matchingRuleCount', 2)
        ->assertJsonPath('data.authorizationRequiredRuleCount', 2)
        ->assertJsonPath('data.autoApproveRuleCount', 1)
        ->assertJsonPath('data.serviceSpecificRuleCount', 1)
        ->assertJsonPath('data.serviceTypeRuleCount', 1)
        ->assertJsonPath('data.coveragePercentMin', 80)
        ->assertJsonPath('data.coveragePercentMax', 90);
});

it('requires reason when retiring billing service catalog item', function (): void {
    $user = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);
    $created = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'PROC-TH-001',
        'serviceName' => 'Theatre Procedure Fee',
        'serviceType' => 'procedure',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-service-catalog/items/'.$created['id'].'/status', [
            'status' => 'retired',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('updates billing service catalog status', function (): void {
    $user = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);
    $created = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'PROC-ENDO-001',
        'serviceName' => 'Endoscopy Procedure Fee',
        'serviceType' => 'procedure',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-service-catalog/items/'.$created['id'].'/status', [
            'status' => 'retired',
            'reason' => 'Replaced by bundled tariff',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'retired')
        ->assertJsonPath('data.statusReason', 'Replaced by bundled tariff');
});

it('lists billing service catalog audit logs when authorized', function (): void {
    $user = makeBillingServiceCatalogUser([
        'billing.service-catalog.manage',
        'billing.service-catalog.view-audit-logs',
    ]);
    $created = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'CONSULT-OPD-003',
        'serviceName' => 'Consultation Level 3',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items/'.$created['id'].'/audit-logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'billing-service-catalog-item.created');
});

it('exports billing service catalog audit logs as csv', function (): void {
    $user = makeBillingServiceCatalogUser([
        'billing.service-catalog.manage',
        'billing.service-catalog.view-audit-logs',
    ]);
    $created = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'CONSULT-OPD-004',
        'serviceName' => 'Consultation Level 4',
    ]);

    $response = $this->actingAs($user)
        ->get('/api/v1/billing-service-catalog/items/'.$created['id'].'/audit-logs/export');

    $response->assertOk();
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    expect($response->streamedContent())->toContain('billing-service-catalog-item.created');
});

it('forbids billing service catalog audit logs without permission', function (): void {
    $user = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);
    $created = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'CONSULT-OPD-005',
        'serviceName' => 'Consultation Level 5',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('writes billing service catalog status transition parity metadata in audit logs', function (): void {
    $user = makeBillingServiceCatalogUser([
        'billing.service-catalog.manage',
        'billing.service-catalog.view-audit-logs',
    ]);

    $item = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'HARDEN-STATUS-001',
        'serviceName' => 'Status Hardening Fee',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-service-catalog/items/'.$item['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Tariff policy review',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $statusAudit = BillingServiceCatalogItemAuditLogModel::query()
        ->where('billing_service_catalog_item_id', $item['id'])
        ->where('action', 'billing-service-catalog-item.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();
    expect($statusAudit?->metadata ?? [])->toMatchArray([
        'transition' => [
            'from' => 'active',
            'to' => 'inactive',
        ],
        'reason_required' => true,
        'reason_provided' => true,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing-service-catalog/items/'.$item['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'billing-service-catalog-item.status.updated');
});

it('rejects billing service catalog detail update when status fields are provided', function (): void {
    $user = makeBillingServiceCatalogUser(['billing.service-catalog.manage']);

    $item = createBillingServiceCatalogItem($user, [
        'serviceCode' => 'HARDEN-GUARD-001',
        'serviceName' => 'Guardrail Fee',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-service-catalog/items/'.$item['id'], [
            'serviceName' => 'Guardrail Fee Updated',
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});
