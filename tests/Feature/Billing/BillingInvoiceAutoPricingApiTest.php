<?php

use App\Models\User;
use App\Modules\Billing\Infrastructure\Models\BillingPayerAuthorizationRuleModel;
use App\Modules\Billing\Infrastructure\Models\BillingPayerContractModel;
use App\Modules\Billing\Infrastructure\Models\BillingPayerContractPriceOverrideModel;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeAutoPricingBillingUser(bool $withUpdateDraftPermission = false): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('billing.invoices.create');
    $user->givePermissionTo('billing.invoices.read');

    if ($withUpdateDraftPermission) {
        $user->givePermissionTo('billing.invoices.update-draft');
    }

    return $user;
}

function makeAutoPricingBillingPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'middle_name' => null,
        'last_name' => 'Mussa',
        'gender' => 'female',
        'date_of_birth' => '1998-03-14',
        'phone' => '+255700111222',
        'email' => null,
        'national_id' => null,
        'country_code' => 'TZ',
        'region' => null,
        'district' => null,
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);
}

function makeServiceCatalogItem(array $overrides = []): BillingServiceCatalogItemModel
{
    return BillingServiceCatalogItemModel::query()->create(array_merge([
        'service_code' => 'CONSULT-OPD-AP01',
        'service_name' => 'OPD Consultation Auto Pricing',
        'service_type' => 'consultation',
        'department' => 'General OPD',
        'unit' => 'service',
        'base_price' => 50000,
        'currency_code' => 'TZS',
        'tax_rate_percent' => 18,
        'is_taxable' => true,
        'effective_from' => now()->subDay()->toDateTimeString(),
        'effective_to' => null,
        'description' => 'Auto pricing catalog item',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function makePayerContract(array $overrides = []): BillingPayerContractModel
{
    return BillingPayerContractModel::query()->create(array_merge([
        'contract_code' => 'NHIF-AUTO-2026',
        'contract_name' => 'NHIF Auto Pricing Contract',
        'payer_type' => 'insurance',
        'payer_name' => 'NHIF',
        'currency_code' => 'TZS',
        'default_coverage_percent' => 80,
        'default_copay_type' => 'percentage',
        'default_copay_value' => 20,
        'requires_pre_authorization' => true,
        'effective_from' => now()->subDay()->toDateTimeString(),
        'effective_to' => null,
        'terms_and_notes' => null,
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function makeAuthorizationRule(string $contractId, array $overrides = []): BillingPayerAuthorizationRuleModel
{
    return BillingPayerAuthorizationRuleModel::query()->create(array_merge([
        'billing_payer_contract_id' => $contractId,
        'rule_code' => 'AUTH-CONS-01',
        'rule_name' => 'Consultation authorization rule',
        'service_code' => 'CONSULT-OPD-AP01',
        'service_type' => 'consultation',
        'department' => 'General OPD',
        'diagnosis_code' => null,
        'priority' => null,
        'min_patient_age_years' => null,
        'max_patient_age_years' => null,
        'gender' => null,
        'amount_threshold' => 40000,
        'quantity_limit' => 2,
        'coverage_decision' => 'covered_with_rule',
        'coverage_percent_override' => 85,
        'copay_type' => 'fixed',
        'copay_value' => 15000,
        'benefit_limit_amount' => 500000,
        'effective_from' => now()->subDay()->toDateTimeString(),
        'effective_to' => null,
        'requires_authorization' => true,
        'auto_approve' => false,
        'authorization_validity_days' => 14,
        'rule_notes' => null,
        'rule_expression' => null,
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function makePriceOverride(string $contractId, array $overrides = []): BillingPayerContractPriceOverrideModel
{
    return BillingPayerContractPriceOverrideModel::query()->create(array_merge([
        'billing_payer_contract_id' => $contractId,
        'service_code' => 'CONSULT-OPD-AP01',
        'service_name' => 'OPD Consultation Auto Pricing',
        'service_type' => 'consultation',
        'department' => 'General OPD',
        'currency_code' => 'TZS',
        'pricing_strategy' => 'fixed_price',
        'override_value' => 42000,
        'effective_from' => now()->subDay()->toDateTimeString(),
        'effective_to' => null,
        'override_notes' => 'Negotiated payer consultation price',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function autoPricingInvoicePayload(string $patientId, array $overrides = []): array
{
    return array_merge([
        'patientId' => $patientId,
        'invoiceDate' => now()->toDateTimeString(),
        'currencyCode' => 'TZS',
        'subtotalAmount' => 0,
        'discountAmount' => 10000,
        'taxAmount' => 0,
        'paidAmount' => 5000,
        'paymentDueAt' => now()->addDays(7)->toDateTimeString(),
        'notes' => 'Auto pricing invoice',
        'autoPriceLineItems' => true,
        'lineItems' => [
            [
                'description' => 'Manual description kept',
                'quantity' => 2,
                'unitPrice' => 1,
                'serviceCode' => 'consult-opd-ap01',
                'unit' => 'service',
            ],
        ],
    ], $overrides);
}

it('auto prices billing invoice line items from service catalog and computes totals', function (): void {
    $user = makeAutoPricingBillingUser();
    $patient = makeAutoPricingBillingPatient();
    makeServiceCatalogItem();

    $this->actingAs($user)
        ->postJson('/api/v1/billing-invoices', autoPricingInvoicePayload($patient->id))
        ->assertCreated()
        ->assertJsonPath('data.pricingMode', 'service_catalog')
        ->assertJsonPath('data.lineItems.0.pricingSource', 'service_catalog')
        ->assertJsonPath('data.lineItems.0.serviceCode', 'CONSULT-OPD-AP01')
        ->assertJsonPath('data.lineItems.0.unitPrice', 50000)
        ->assertJsonPath('data.lineItems.0.lineTotal', 100000)
        ->assertJsonPath('data.subtotalAmount', '100000.00')
        ->assertJsonPath('data.taxAmount', '18000.00')
        ->assertJsonPath('data.totalAmount', '108000.00')
        ->assertJsonPath('data.paidAmount', '5000.00')
        ->assertJsonPath('data.balanceAmount', '103000.00')
        ->assertJsonPath('data.pricingContext.autoPricingApplied', true);
});

it('rejects auto pricing request when line item service code is missing', function (): void {
    $user = makeAutoPricingBillingUser();
    $patient = makeAutoPricingBillingPatient();
    makeServiceCatalogItem();

    $this->actingAs($user)
        ->postJson('/api/v1/billing-invoices', autoPricingInvoicePayload($patient->id, [
            'lineItems' => [
                [
                    'description' => 'Missing code line',
                    'quantity' => 1,
                    'unitPrice' => 1,
                ],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lineItems']);
});

it('applies payer authorization rule summary during auto pricing when contract is selected', function (): void {
    $user = makeAutoPricingBillingUser();
    $patient = makeAutoPricingBillingPatient();
    makeServiceCatalogItem();
    $contract = makePayerContract();
    makeAuthorizationRule($contract->id);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-invoices', autoPricingInvoicePayload($patient->id, [
            'billingPayerContractId' => $contract->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.billingPayerContractId', $contract->id)
        ->assertJsonPath('data.lineItems.0.coverage.decision', 'covered_with_rule')
        ->assertJsonPath('data.lineItems.0.coverage.effectiveCoveragePercent', 85)
        ->assertJsonPath('data.lineItems.0.coverage.copayType', 'fixed')
        ->assertJsonPath('data.lineItems.0.authorization.required', true)
        ->assertJsonPath('data.lineItems.0.authorization.autoApproved', false)
        ->assertJsonPath('data.lineItems.0.authorization.matchedRuleCodes.0', 'AUTH-CONS-01')
        ->assertJsonPath('data.coverageSummary.lineItemsCoveredWithRule', 1)
        ->assertJsonPath('data.pricingContext.authorizationSummary.lineItemsRequiringAuthorization', 1)
        ->assertJsonPath('data.pricingContext.coverageSummary.lineItemsCoveredWithRule', 1)
        ->assertJsonPath('data.pricingContext.authorizationSummary.matchedRuleCount', 1)
        ->assertJsonPath('data.claimReadiness.coverageSummary.lineItemsCoveredWithRule', 1)
        ->assertJsonPath('data.claimReadiness.state', 'preauthorization_required');
});

it('applies exclusion coverage posture during auto pricing when contract policy excludes the service', function (): void {
    $user = makeAutoPricingBillingUser();
    $patient = makeAutoPricingBillingPatient();
    makeServiceCatalogItem();
    $contract = makePayerContract([
        'contract_code' => 'NHIF-EXCLUSION-2026',
    ]);
    makeAuthorizationRule($contract->id, [
        'rule_code' => 'EXCLUDE-CONS-01',
        'rule_name' => 'Consultation excluded from cover',
        'coverage_decision' => 'excluded',
        'coverage_percent_override' => 0,
        'requires_authorization' => false,
        'auto_approve' => false,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-invoices', autoPricingInvoicePayload($patient->id, [
            'billingPayerContractId' => $contract->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.lineItems.0.coverage.decision', 'excluded')
        ->assertJsonPath('data.lineItems.0.coverage.effectiveCoveragePercent', 0)
        ->assertJsonPath('data.lineItems.0.coverage.selectedRuleCode', 'EXCLUDE-CONS-01')
        ->assertJsonPath('data.coverageSummary.lineItemsExcluded', 1)
        ->assertJsonPath('data.pricingContext.coverageSummary.lineItemsExcluded', 1)
        ->assertJsonPath('data.pricingContext.coverageSummary.lineItemsUsingPolicyRule', 1)
        ->assertJsonPath('data.claimReadiness.ready', false)
        ->assertJsonPath('data.claimReadiness.state', 'coverage_exception');
});

it('marks claim readiness as coverage review required when contract policy needs manual review', function (): void {
    $user = makeAutoPricingBillingUser();
    $patient = makeAutoPricingBillingPatient();
    makeServiceCatalogItem();
    $contract = makePayerContract([
        'contract_code' => 'NHIF-MANUAL-REVIEW-2026',
    ]);
    makeAuthorizationRule($contract->id, [
        'rule_code' => 'MANUAL-CONS-01',
        'rule_name' => 'Consultation manual review',
        'coverage_decision' => 'manual_review',
        'coverage_percent_override' => null,
        'requires_authorization' => false,
        'auto_approve' => false,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-invoices', autoPricingInvoicePayload($patient->id, [
            'billingPayerContractId' => $contract->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.lineItems.0.coverage.decision', 'manual_review')
        ->assertJsonPath('data.coverageSummary.lineItemsManualReview', 1)
        ->assertJsonPath('data.claimReadiness.ready', false)
        ->assertJsonPath('data.claimReadiness.state', 'coverage_review_required');
});

it('applies payer contract price overrides during auto pricing when contract pricing exists', function (): void {
    $user = makeAutoPricingBillingUser();
    $patient = makeAutoPricingBillingPatient();
    makeServiceCatalogItem();
    $contract = makePayerContract([
        'contract_code' => 'NHIF-NEGOTIATED-2026',
    ]);
    makePriceOverride($contract->id, [
        'override_value' => 42000,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-invoices', autoPricingInvoicePayload($patient->id, [
            'billingPayerContractId' => $contract->id,
            'discountAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.lineItems.0.pricingSource', 'payer_contract_price_override')
        ->assertJsonPath('data.lineItems.0.catalogUnitPrice', 50000)
        ->assertJsonPath('data.lineItems.0.unitPrice', 42000)
        ->assertJsonPath('data.lineItems.0.lineTotal', 84000)
        ->assertJsonPath('data.subtotalAmount', '84000.00')
        ->assertJsonPath('data.pricingContext.priceOverrideSummary.matchedOverrideCount', 1)
        ->assertJsonPath('data.pricingContext.priceOverrideSummary.matchedServiceCodes.0', 'CONSULT-OPD-AP01');
});

it('previews billing invoice pricing with negotiated price and coverage posture before save', function (): void {
    $user = makeAutoPricingBillingUser(withUpdateDraftPermission: true);
    $patient = makeAutoPricingBillingPatient();
    makeServiceCatalogItem();
    $contract = makePayerContract([
        'contract_code' => 'NHIF-PREVIEW-2026',
        'requires_pre_authorization' => false,
    ]);
    makeAuthorizationRule($contract->id, [
        'rule_code' => 'PREVIEW-CONS-01',
        'coverage_decision' => 'covered_with_rule',
        'coverage_percent_override' => 90,
        'copay_type' => 'fixed',
        'copay_value' => 5000,
        'requires_authorization' => false,
        'auto_approve' => true,
    ]);
    makePriceOverride($contract->id, [
        'override_value' => 41000,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-invoices/preview', autoPricingInvoicePayload($patient->id, [
            'billingPayerContractId' => $contract->id,
            'discountAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.lineItems.0.pricingSource', 'payer_contract_price_override')
        ->assertJsonPath('data.lineItems.0.unitPrice', 41000)
        ->assertJsonPath('data.lineItems.0.coverage.decision', 'covered_with_rule')
        ->assertJsonPath('data.coverageSummary.lineItemsCoveredWithRule', 1)
        ->assertJsonPath('data.claimReadiness.ready', true)
        ->assertJsonPath('data.claimReadiness.state', 'ready');
});

it('rejects auto pricing when selected payer contract is inactive', function (): void {
    $user = makeAutoPricingBillingUser();
    $patient = makeAutoPricingBillingPatient();
    makeServiceCatalogItem();
    $inactiveContract = makePayerContract([
        'contract_code' => 'NHIF-INACTIVE-2026',
        'status' => 'inactive',
        'status_reason' => 'Paused',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-invoices', autoPricingInvoicePayload($patient->id, [
            'billingPayerContractId' => $inactiveContract->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['billingPayerContractId']);
});

it('recalculates auto priced invoice line items on draft update when auto pricing is enabled', function (): void {
    $user = makeAutoPricingBillingUser(withUpdateDraftPermission: true);
    $patient = makeAutoPricingBillingPatient();
    makeServiceCatalogItem();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing-invoices', autoPricingInvoicePayload($patient->id, [
            'discountAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing-invoices/'.$created['id'], [
            'autoPriceLineItems' => true,
            'discountAmount' => 0,
            'lineItems' => [
                [
                    'description' => 'Updated quantity',
                    'quantity' => 3,
                    'unitPrice' => 1,
                    'serviceCode' => 'CONSULT-OPD-AP01',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.pricingMode', 'service_catalog')
        ->assertJsonPath('data.lineItems.0.quantity', 3)
        ->assertJsonPath('data.lineItems.0.unitPrice', 50000)
        ->assertJsonPath('data.subtotalAmount', '150000.00')
        ->assertJsonPath('data.taxAmount', '27000.00')
        ->assertJsonPath('data.totalAmount', '177000.00');
});
