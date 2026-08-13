<?php

use App\Models\User;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Modules\Billing\Infrastructure\Models\BillingPayerAuthorizationRuleAuditLogModel;
use App\Modules\Billing\Infrastructure\Models\BillingPayerContractAuditLogModel;
use App\Modules\Billing\Infrastructure\Models\BillingPayerContractPriceOverrideAuditLogModel;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureFacilitySubscriptionEntitlement::class);
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
});

function billingPayerContractPayload(array $overrides = []): array
{
    return array_merge([
        'contractCode' => 'NHIF-OPD-2026',
        'contractName' => 'NHIF OPD Standard 2026',
        'payerType' => 'insurance',
        'payerName' => 'NHIF',
        'payerPlanCode' => 'NHIF-STD',
        'payerPlanName' => 'Standard',
        'currencyCode' => 'tzs',
        'defaultCoveragePercent' => 80,
        'defaultCopayType' => 'percentage',
        'defaultCopayValue' => 20,
        'requiresPreAuthorization' => true,
        'claimSubmissionDeadlineDays' => 30,
        'settlementCycleDays' => 45,
        'effectiveFrom' => now()->startOfDay()->toDateTimeString(),
        'termsAndNotes' => 'Base contract terms',
        'metadata' => [
            'contractClass' => 'general',
        ],
    ], $overrides);
}

function billingPayerAuthorizationRulePayload(array $overrides = []): array
{
    return array_merge([
        'ruleCode' => 'AUTH-MRI-001',
        'ruleName' => 'MRI requires pre-authorization',
        'serviceCode' => 'MRI-BRAIN',
        'serviceType' => 'radiology',
        'department' => 'Radiology',
        'diagnosisCode' => 'G44.2',
        'priority' => 'routine',
        'minPatientAgeYears' => 18,
        'maxPatientAgeYears' => 90,
        'gender' => 'any',
        'amountThreshold' => 250000,
        'quantityLimit' => 1,
        'coverageDecision' => 'covered_with_rule',
        'coveragePercentOverride' => 85,
        'copayType' => 'fixed',
        'copayValue' => 15000,
        'benefitLimitAmount' => 500000,
        'effectiveFrom' => now()->startOfDay()->toDateString(),
        'requiresAuthorization' => true,
        'autoApprove' => false,
        'authorizationValidityDays' => 14,
        'ruleNotes' => 'Authorization is mandatory for MRI.',
        'ruleExpression' => [
            'all' => [
                ['field' => 'serviceType', 'operator' => 'eq', 'value' => 'radiology'],
            ],
        ],
        'metadata' => [
            'reviewOwner' => 'payer-team',
        ],
    ], $overrides);
}

function billingPayerContractPriceOverridePayload(array $overrides = []): array
{
    return array_merge([
        'serviceCode' => 'CONSULT-OPD-001',
        'serviceName' => 'Consultation Override',
        'serviceType' => 'consultation',
        'department' => 'General OPD',
        'pricingStrategy' => 'fixed_price',
        'overrideValue' => 42000,
        'effectiveFrom' => now()->startOfDay()->toDateTimeString(),
        'overrideNotes' => 'Negotiated payer price',
        'metadata' => [
            'contractTier' => 'standard',
        ],
    ], $overrides);
}

function makeBillingPayerContractUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function createBillingPayerContract(User $user, array $payload = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts', billingPayerContractPayload($payload))
        ->assertCreated()
        ->json('data');
}

function createBillingServiceCatalogForContractOverride(array $overrides = []): BillingServiceCatalogItemModel
{
    return BillingServiceCatalogItemModel::query()->create(array_merge([
        'service_code' => 'CONSULT-OPD-001',
        'service_name' => 'OPD Consultation',
        'service_type' => 'consultation',
        'department' => 'General OPD',
        'unit' => 'service',
        'base_price' => 50000,
        'currency_code' => 'TZS',
        'tax_rate_percent' => 0,
        'is_taxable' => false,
        'effective_from' => now()->subDay()->toDateTimeString(),
        'effective_to' => null,
        'description' => 'Contract override service',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

/**
 * PricingEngine_Migration_Plan.md Phase 5: payer price overrides resolve
 * their base price/metadata from chargeable_items now, not the legacy
 * service catalog -- createBillingServiceCatalogForContractOverride() above
 * is kept only as a legacy fixture proving it's genuinely ignored now.
 */
function createChargeableItemForContractOverride(string $code = 'CONSULT-OPD-001', float $price = 50000, string $currencyCode = 'TZS'): void
{
    if (ChargeableItemModel::query()->whereRaw('UPPER(code) = ?', [strtoupper($code)])->exists()) {
        return;
    }

    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->fill([
        'catalog_type' => 'consultation', 'charge_model' => 'flat', 'code' => $code, 'name' => 'OPD Consultation', 'status' => 'active',
    ]);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id, 'currency_code' => $currencyCode, 'unit_price' => $price, 'status' => 'active',
    ]);
}

it('requires authentication for billing payer contract creation', function (): void {
    $this->postJson('/api/v1/billing-payer-contracts', billingPayerContractPayload())
        ->assertUnauthorized();
});

it('forbids billing payer contract list without read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/billing-payer-contracts')
        ->assertForbidden();
});

it('creates billing payer contract with normalized code and currency', function (): void {
    $user = makeBillingPayerContractUser(['billing.payer-contracts.manage']);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts', billingPayerContractPayload())
        ->assertCreated()
        ->assertJsonPath('data.contractCode', 'NHIF-OPD-2026')
        ->assertJsonPath('data.currencyCode', 'TZS')
        ->assertJsonPath('data.status', 'active');
});

it('rejects duplicate billing payer contract code in same scope', function (): void {
    $user = makeBillingPayerContractUser(['billing.payer-contracts.manage']);
    createBillingPayerContract($user);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts', billingPayerContractPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['contractCode']);
});

it('lists and filters billing payer contracts', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.read',
    ]);

    createBillingPayerContract($user, [
        'contractCode' => 'NHIF-IPD-2026',
        'contractName' => 'NHIF IPD Standard 2026',
        'payerType' => 'insurance',
        'requiresPreAuthorization' => true,
    ]);
    createBillingPayerContract($user, [
        'contractCode' => 'EMP-ABC-2026',
        'contractName' => 'ABC Employer Contract',
        'payerType' => 'employer',
        'payerName' => 'ABC Corp',
        'requiresPreAuthorization' => false,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing-payer-contracts?q=nhif&payerType=insurance&requiresPreAuthorization=true')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.contractCode', 'NHIF-IPD-2026');
});

it('returns billing payer contract status counts', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.read',
    ]);

    $active = createBillingPayerContract($user, [
        'contractCode' => 'GOV-OPD-2026',
        'contractName' => 'Government OPD Contract',
        'payerType' => 'government',
    ]);
    createBillingPayerContract($user, [
        'contractCode' => 'DONOR-CHILD-2026',
        'contractName' => 'Donor Child Health Contract',
        'payerType' => 'donor',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$active['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Annual contract renegotiation',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/billing-payer-contracts/status-counts')
        ->assertOk()
        ->assertJsonPath('data.active', 1)
        ->assertJsonPath('data.inactive', 1)
        ->assertJsonPath('data.total', 2);
});

it('updates billing payer contract fields', function (): void {
    $user = makeBillingPayerContractUser(['billing.payer-contracts.manage']);
    $created = createBillingPayerContract($user, [
        'contractCode' => 'EMP-MED-2026',
        'contractName' => 'Employer Medical Contract',
        'payerType' => 'employer',
        'payerName' => 'MediCorp',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$created['id'], [
            'contractName' => 'Employer Medical Contract Revised',
            'currencyCode' => 'usd',
            'defaultCoveragePercent' => 90,
            'requiresPreAuthorization' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.contractName', 'Employer Medical Contract Revised')
        ->assertJsonPath('data.currencyCode', 'USD')
        ->assertJsonPath('data.defaultCoveragePercent', '90.00')
        ->assertJsonPath('data.requiresPreAuthorization', false);
});

it('requires reason when retiring billing payer contract', function (): void {
    $user = makeBillingPayerContractUser(['billing.payer-contracts.manage']);
    $created = createBillingPayerContract($user, [
        'contractCode' => 'EMP-RETIRE-2026',
        'payerType' => 'employer',
        'payerName' => 'Retire Corp',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$created['id'].'/status', [
            'status' => 'retired',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('lists and exports billing payer contract audit logs when authorized', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.view-audit-logs',
    ]);
    $created = createBillingPayerContract($user, [
        'contractCode' => 'AUDIT-TEST-2026',
        'payerType' => 'insurance',
        'payerName' => 'Audit Payer',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing-payer-contracts/'.$created['id'].'/audit-logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'billing-payer-contract.created');

    $response = $this->actingAs($user)
        ->get('/api/v1/billing-payer-contracts/'.$created['id'].'/audit-logs/export');

    $response->assertOk();
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    expect($response->streamedContent())->toContain('billing-payer-contract.created');
});

it('forbids billing payer contract audit logs without permission', function (): void {
    $user = makeBillingPayerContractUser(['billing.payer-contracts.manage']);
    $created = createBillingPayerContract($user, [
        'contractCode' => 'AUDIT-NO-PERM-2026',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing-payer-contracts/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('creates, updates, and lists authorization rules under contract', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.read',
        'billing.payer-contracts.manage-authorization-rules',
    ]);
    $contract = createBillingPayerContract($user, [
        'contractCode' => 'RULES-BASE-2026',
    ]);

    $rule = $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules', billingPayerAuthorizationRulePayload())
        ->assertCreated()
        ->assertJsonPath('data.ruleCode', 'AUTH-MRI-001')
        ->assertJsonPath('data.coverageDecision', 'covered_with_rule')
        ->assertJsonPath('data.coveragePercentOverride', '85.00')
        ->assertJsonPath('data.copayType', 'fixed')
        ->assertJsonPath('data.status', 'active')
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules?serviceType=radiology&coverageDecision=covered_with_rule')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.ruleCode', 'AUTH-MRI-001');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules/'.$rule['id'], [
            'ruleName' => 'MRI prior authorization required',
            'coverageDecision' => 'excluded',
            'coveragePercentOverride' => 0,
            'autoApprove' => true,
            'authorizationValidityDays' => 21,
        ])
        ->assertOk()
        ->assertJsonPath('data.ruleName', 'MRI prior authorization required')
        ->assertJsonPath('data.coverageDecision', 'excluded')
        ->assertJsonPath('data.coveragePercentOverride', '0.00')
        ->assertJsonPath('data.autoApprove', true)
        ->assertJsonPath('data.authorizationValidityDays', 21);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules/'.$rule['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Temporary hold pending policy review',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');
});

it('returns payer contract policy summary with family matrix and benefit bands', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.read',
        'billing.payer-contracts.manage-authorization-rules',
    ]);
    $contract = createBillingPayerContract($user, [
        'contractCode' => 'POLICY-SUMMARY-2026',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules', billingPayerAuthorizationRulePayload([
            'ruleCode' => 'RAD-COVER-01',
            'ruleName' => 'Radiology covered with rule',
            'serviceType' => 'radiology',
            'serviceCode' => null,
            'coverageDecision' => 'covered_with_rule',
            'coveragePercentOverride' => 85,
            'copayType' => 'fixed',
            'copayValue' => 15000,
            'amountThreshold' => 250000,
            'benefitLimitAmount' => 500000,
        ]))
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules', billingPayerAuthorizationRulePayload([
            'ruleCode' => 'RAD-EXCLUDE-01',
            'ruleName' => 'Exclude selected MRI',
            'serviceType' => 'radiology',
            'serviceCode' => 'MRI-BRAIN',
            'coverageDecision' => 'excluded',
            'coveragePercentOverride' => 0,
            'copayType' => 'none',
            'copayValue' => null,
            'quantityLimit' => 1,
            'benefitLimitAmount' => null,
            'effectiveTo' => now()->addMonth()->toDateString(),
        ]))
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules', billingPayerAuthorizationRulePayload([
            'ruleCode' => 'CONS-REVIEW-01',
            'ruleName' => 'Consult review band',
            'serviceType' => 'consultation',
            'serviceCode' => 'CONSULT-OPD-001',
            'coverageDecision' => 'manual_review',
            'coveragePercentOverride' => null,
            'copayType' => 'percentage',
            'copayValue' => 10,
            'amountThreshold' => null,
            'quantityLimit' => null,
            'benefitLimitAmount' => 200000,
            'requiresAuthorization' => false,
            'autoApprove' => true,
        ]))
        ->assertCreated();

    $this->actingAs($user)
        ->getJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules/summary')
        ->assertOk()
        ->assertJsonPath('data.overview.activePolicies', 3)
        ->assertJsonPath('data.overview.serviceFamilies', 2)
        ->assertJsonPath('data.overview.excludedPolicies', 1)
        ->assertJsonPath('data.overview.manualReviewPolicies', 1)
        ->assertJsonPath('data.overview.authorizationRequiredPolicies', 2)
        ->assertJsonPath('data.overview.autoApprovePolicies', 1)
        ->assertJsonPath('data.overview.benefitBandPolicies', 3)
        ->assertJsonFragment([
            'label' => 'Radiology',
            'policyCount' => 2,
            'dominantDecision' => 'excluded',
        ])
        ->assertJsonFragment([
            'label' => 'Consultation',
            'policyCount' => 1,
            'dominantDecision' => 'manual_review',
        ])
        ->assertJsonFragment([
            'ruleCode' => 'RAD-COVER-01',
            'benefitLimitAmount' => '500000.00',
        ])
        ->assertJsonFragment([
            'ruleCode' => 'CONS-REVIEW-01',
            'copayType' => 'percentage',
        ]);
});

it('creates, updates, and lists price overrides under contract', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.read',
        'billing.payer-contracts.manage-price-overrides',
    ]);
    createBillingServiceCatalogForContractOverride();
    createChargeableItemForContractOverride();
    $contract = createBillingPayerContract($user, [
        'contractCode' => 'PRICING-BASE-2026',
    ]);

    $override = $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides', billingPayerContractPriceOverridePayload())
        ->assertCreated()
        ->assertJsonPath('data.serviceCode', 'CONSULT-OPD-001')
        ->assertJsonPath('data.pricingStrategy', 'fixed_price')
        ->assertJsonPath('data.overrideValue', '42000.00')
        ->assertJsonPath('data.catalogPricingStatus', 'matched_active_service_price')
        ->assertJsonPath('data.catalogBasePrice', '50000.00')
        ->assertJsonPath('data.resolvedNegotiatedPrice', '42000.00')
        ->assertJsonPath('data.varianceAmount', '-8000.00')
        ->assertJsonPath('data.variancePercent', '-16.00')
        ->assertJsonPath('data.varianceDirection', 'discount')
        ->assertJsonPath('data.status', 'active')
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides?serviceType=consultation')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.serviceCode', 'CONSULT-OPD-001')
        ->assertJsonPath('data.0.catalogBasePrice', '50000.00')
        ->assertJsonPath('data.0.resolvedNegotiatedPrice', '42000.00');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides/'.$override['id'], [
            'pricingStrategy' => 'discount_percent',
            'overrideValue' => 15,
            'overrideNotes' => '15 percent contractual discount',
        ])
        ->assertOk()
        ->assertJsonPath('data.pricingStrategy', 'discount_percent')
        ->assertJsonPath('data.overrideValue', '15.00')
        ->assertJsonPath('data.catalogBasePrice', '50000.00')
        ->assertJsonPath('data.resolvedNegotiatedPrice', '42500.00')
        ->assertJsonPath('data.varianceAmount', '-7500.00')
        ->assertJsonPath('data.variancePercent', '-15.00')
        ->assertJsonPath('data.overrideNotes', '15 percent contractual discount');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides/'.$override['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Temporary payer hold',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');
});

it('rejects overlapping price overrides for the same service window', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.manage-price-overrides',
    ]);
    createBillingServiceCatalogForContractOverride();
    createChargeableItemForContractOverride();
    $contract = createBillingPayerContract($user, [
        'contractCode' => 'PRICING-DUP-2026',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides', billingPayerContractPriceOverridePayload())
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides', billingPayerContractPriceOverridePayload([
            'overrideValue' => 41000,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['effectiveFrom']);
});

it('rejects duplicate authorization rule code per contract', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.manage-authorization-rules',
    ]);
    $contract = createBillingPayerContract($user, [
        'contractCode' => 'RULES-DUP-2026',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules', billingPayerAuthorizationRulePayload())
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules', billingPayerAuthorizationRulePayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ruleCode']);
});

it('lists and exports authorization rule audit logs when authorized', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.manage-authorization-rules',
        'billing.payer-contracts.view-authorization-audit-logs',
    ]);
    $contract = createBillingPayerContract($user, [
        'contractCode' => 'RULE-AUDIT-2026',
    ]);

    $rule = $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules', billingPayerAuthorizationRulePayload())
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules/'.$rule['id'].'/audit-logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'billing-payer-authorization-rule.created');

    $response = $this->actingAs($user)
        ->get('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules/'.$rule['id'].'/audit-logs/export');

    $response->assertOk();
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    expect($response->streamedContent())->toContain('billing-payer-authorization-rule.created');
});

it('lists and exports price override audit logs when authorized', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.manage-price-overrides',
        'billing.payer-contracts.view-price-override-audit-logs',
    ]);
    createBillingServiceCatalogForContractOverride();
    createChargeableItemForContractOverride();
    $contract = createBillingPayerContract($user, [
        'contractCode' => 'PRICE-AUDIT-2026',
    ]);

    $override = $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides', billingPayerContractPriceOverridePayload())
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides/'.$override['id'].'/audit-logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'billing-payer-contract-price-override.created');

    $response = $this->actingAs($user)
        ->get('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides/'.$override['id'].'/audit-logs/export');

    $response->assertOk();
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    expect($response->streamedContent())->toContain('billing-payer-contract-price-override.created');
});

it('forbids price override audit logs without permission', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.manage-price-overrides',
    ]);
    createBillingServiceCatalogForContractOverride();
    createChargeableItemForContractOverride();
    $contract = createBillingPayerContract($user, [
        'contractCode' => 'PRICE-AUDIT-NO-PERM-2026',
    ]);
    $override = $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides', billingPayerContractPriceOverridePayload())
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides/'.$override['id'].'/audit-logs')
        ->assertForbidden();
});

it('forbids authorization rule audit logs without permission', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.manage-authorization-rules',
    ]);
    $contract = createBillingPayerContract($user, [
        'contractCode' => 'RULE-AUDIT-NO-PERM-2026',
    ]);
    $rule = $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules', billingPayerAuthorizationRulePayload())
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules/'.$rule['id'].'/audit-logs')
        ->assertForbidden();
});

it('writes payer contract and authorization rule status transition parity metadata', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.read',
        'billing.payer-contracts.manage-price-overrides',
        'billing.payer-contracts.manage-authorization-rules',
        'billing.payer-contracts.view-audit-logs',
        'billing.payer-contracts.view-price-override-audit-logs',
        'billing.payer-contracts.view-authorization-audit-logs',
    ]);

    $contract = createBillingPayerContract($user, [
        'contractCode' => 'STATUS-PARITY-2026',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Contract review window',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $contractStatusAudit = BillingPayerContractAuditLogModel::query()
        ->where('billing_payer_contract_id', $contract['id'])
        ->where('action', 'billing-payer-contract.status.updated')
        ->latest('created_at')
        ->first();

    expect($contractStatusAudit)->not->toBeNull();
    expect($contractStatusAudit?->metadata ?? [])->toMatchArray([
        'transition' => [
            'from' => 'active',
            'to' => 'inactive',
        ],
        'reason_required' => true,
        'reason_provided' => true,
    ]);

    $rule = $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules', billingPayerAuthorizationRulePayload([
            'ruleCode' => 'AUTH-STATUS-PARITY-001',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules/'.$rule['id'].'/status', [
            'status' => 'retired',
            'reason' => 'Superseded by updated rule set',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'retired');

    $ruleStatusAudit = BillingPayerAuthorizationRuleAuditLogModel::query()
        ->where('billing_payer_authorization_rule_id', $rule['id'])
        ->where('action', 'billing-payer-authorization-rule.status.updated')
        ->latest('created_at')
        ->first();

    expect($ruleStatusAudit)->not->toBeNull();
    expect($ruleStatusAudit?->metadata ?? [])->toMatchArray([
        'billing_payer_contract_id' => $contract['id'],
        'transition' => [
            'from' => 'active',
            'to' => 'retired',
        ],
        'reason_required' => true,
        'reason_provided' => true,
    ]);

    createBillingServiceCatalogForContractOverride();
    createChargeableItemForContractOverride();
    $override = $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides', billingPayerContractPriceOverridePayload([
            'serviceCode' => 'CONSULT-OPD-001',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides/'.$override['id'].'/status', [
            'status' => 'retired',
            'reason' => 'Superseded by updated negotiated rate',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'retired');

    $overrideStatusAudit = BillingPayerContractPriceOverrideAuditLogModel::query()
        ->where('billing_payer_contract_price_override_id', $override['id'])
        ->where('action', 'billing-payer-contract-price-override.status.updated')
        ->latest('created_at')
        ->first();

    expect($overrideStatusAudit)->not->toBeNull();
    expect($overrideStatusAudit?->metadata ?? [])->toMatchArray([
        'billing_payer_contract_id' => $contract['id'],
        'transition' => [
            'from' => 'active',
            'to' => 'retired',
        ],
        'reason_required' => true,
        'reason_provided' => true,
    ]);
});

it('rejects billing payer contract and authorization rule detail updates with status fields', function (): void {
    $user = makeBillingPayerContractUser([
        'billing.payer-contracts.manage',
        'billing.payer-contracts.manage-price-overrides',
        'billing.payer-contracts.manage-authorization-rules',
    ]);

    $contract = createBillingPayerContract($user, [
        'contractCode' => 'DETAIL-GUARD-2026',
    ]);

    $rule = $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules', billingPayerAuthorizationRulePayload([
            'ruleCode' => 'AUTH-DETAIL-GUARD-001',
        ]))
        ->assertCreated()
        ->json('data');

    createBillingServiceCatalogForContractOverride();
    createChargeableItemForContractOverride();
    $override = $this->actingAs($user)
        ->postJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides', billingPayerContractPriceOverridePayload())
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$contract['id'], [
            'contractName' => 'Updated Contract Name',
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/authorization-rules/'.$rule['id'], [
            'ruleName' => 'Updated Rule Name',
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-payer-contracts/'.$contract['id'].'/price-overrides/'.$override['id'], [
            'overrideNotes' => 'Updated override note',
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});
