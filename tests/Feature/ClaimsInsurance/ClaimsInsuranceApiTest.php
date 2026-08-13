<?php

use App\Models\User;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\PatientInsuranceModel;
use App\Modules\ClaimsInsurance\Infrastructure\Models\ClaimsInsuranceCaseAuditLogModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
});

function makeClaimsInsuranceUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @return array{id: string, invoiceId: string}
 */
function createPatientAndInvoiceForClaim(float $totalAmount = 95000.00, ?string $currencyCode = 'TZS', array $invoiceOverrides = []): array
{
    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-'.strtoupper(Str::random(8)),
        'first_name' => 'Claim',
        'last_name' => 'Patient',
        'gender' => 'female',
        'date_of_birth' => '1992-04-15',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $invoice = BillingInvoiceModel::query()->create(array_merge([
        'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
        'patient_id' => $patient->id,
        'invoice_date' => now()->toDateTimeString(),
        'currency_code' => $currencyCode ?? '',
        'subtotal_amount' => $totalAmount,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => $totalAmount,
        'paid_amount' => 0,
        'balance_amount' => $totalAmount,
        'line_items' => [],
        'pricing_context' => [
            'payerSummary' => [
                'payerType' => 'insurance',
                'payerName' => 'NHIF',
                'expectedPayerAmount' => $totalAmount,
                'requiresPreAuthorization' => false,
            ],
            'claimReadiness' => [
                'claimEligible' => true,
                'ready' => true,
                'blockingReasons' => [],
            ],
        ],
        'status' => 'issued',
    ], $invoiceOverrides));

    return [
        'id' => (string) $patient->id,
        'invoiceId' => (string) $invoice->id,
    ];
}

/**
 * @return array<string, mixed>
 */
function createClaimCase(User $user, string $invoiceId, array $overrides = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/claims-insurance', array_merge([
            'invoiceId' => $invoiceId,
            'payerType' => 'insurance',
            'payerName' => 'NHIF',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

function approveClaimCase(User $user, string $claimId, float $approvedAmount): void
{
    test()->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claimId.'/status', [
            'status' => 'approved',
            'adjudicatedAt' => now()->toDateTimeString(),
            'approvedAmount' => $approvedAmount,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
}

it('creates claim case with invoice financial snapshot and pending reconciliation status', function (): void {
    $user = makeClaimsInsuranceUser(['claims.insurance.create']);
    $context = createPatientAndInvoiceForClaim(95000);

    $this->actingAs($user)
        ->postJson('/api/v1/claims-insurance', [
            'invoiceId' => $context['invoiceId'],
            'payerType' => 'insurance',
            'payerName' => 'NHIF',
        ])
        ->assertCreated()
        ->assertJsonPath('data.claimAmount', '95000.00')
        ->assertJsonPath('data.currencyCode', 'TZS')
        ->assertJsonPath('data.reconciliationStatus', 'pending');
});

it('enriches claim case from patient insurance record', function (): void {
    $user = makeClaimsInsuranceUser(['claims.insurance.create']);
    $context = createPatientAndInvoiceForClaim(95000, 'TZS', [
        'pricing_context' => [
            'payerSummary' => [
                'payerType' => 'insurance',
                'payerName' => 'NHIF',
                'payerPlanName' => 'Standard',
                'expectedPayerAmount' => 95000,
                'requiresPreAuthorization' => false,
            ],
            'claimReadiness' => [
                'claimEligible' => true,
                'ready' => true,
                'blockingReasons' => [],
            ],
        ],
    ]);
    $record = PatientInsuranceModel::query()->create([
        'patient_id' => $context['id'],
        'insurance_type' => 'insurance',
        'insurance_provider' => 'NHIF',
        'plan_name' => 'Standard',
        'member_id' => 'NHIF-12345',
        'policy_number' => 'POL-2026-1',
        'card_number' => 'CARD-12345',
        'verification_reference' => 'VRF-001',
        'effective_date' => now()->subDay(),
        'status' => 'active',
        'verification_status' => 'verified',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/claims-insurance', [
            'invoiceId' => $context['invoiceId'],
            'payerType' => 'insurance',
            'payerName' => 'NHIF',
            'patientInsuranceRecordId' => $record->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.patientInsuranceRecordId', $record->id)
        ->assertJsonPath('data.payerPlanName', 'Standard')
        ->assertJsonPath('data.memberId', 'NHIF-12345')
        ->assertJsonPath('data.policyNumber', 'POL-2026-1')
        ->assertJsonPath('data.cardNumber', 'CARD-12345')
        ->assertJsonPath('data.verificationReference', 'VRF-001')
        ->assertJsonPath('data.claimReadiness.verificationStatus', 'verified');
});

it('defaults claim currency from active country profile when invoice currency is missing', function (): void {
    config()->set('country_profiles.active', 'KE');

    $user = makeClaimsInsuranceUser(['claims.insurance.create']);
    $context = createPatientAndInvoiceForClaim(95000, '');

    $this->actingAs($user)
        ->postJson('/api/v1/claims-insurance', [
            'invoiceId' => $context['invoiceId'],
            'payerType' => 'insurance',
            'payerName' => 'NHIF',
        ])
        ->assertCreated()
        ->assertJsonPath('data.currencyCode', 'KES')
        ->assertJsonPath('data.reconciliationStatus', 'pending');
});

it('rejects claim creation for a self-pay invoice', function (): void {
    $user = makeClaimsInsuranceUser(['claims.insurance.create']);
    $context = createPatientAndInvoiceForClaim(50000, 'TZS', [
        'pricing_context' => [
            'payerSummary' => [
                'payerType' => 'self_pay',
                'expectedPayerAmount' => 0,
                'requiresPreAuthorization' => false,
            ],
            'claimReadiness' => [
                'claimEligible' => false,
                'ready' => false,
                'blockingReasons' => ['Self-pay invoice has no payer-sponsored balance.'],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/claims-insurance', [
            'invoiceId' => $context['invoiceId'],
            'payerType' => 'insurance',
            'payerName' => 'NHIF',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['invoiceId']);
});

it('rejects claim creation before the invoice is issued', function (): void {
    $user = makeClaimsInsuranceUser(['claims.insurance.create']);
    $context = createPatientAndInvoiceForClaim(50000, 'TZS', [
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/claims-insurance', [
            'invoiceId' => $context['invoiceId'],
            'payerType' => 'insurance',
            'payerName' => 'NHIF',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['invoiceId']);
});

it('prevents duplicate active claims for the same invoice', function (): void {
    $user = makeClaimsInsuranceUser(['claims.insurance.create']);
    $context = createPatientAndInvoiceForClaim(50000);

    createClaimCase($user, $context['invoiceId']);

    $this->actingAs($user)
        ->postJson('/api/v1/claims-insurance', [
            'invoiceId' => $context['invoiceId'],
            'payerType' => 'insurance',
            'payerName' => 'NHIF',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['invoiceId']);
});

it('updates reconciliation and derives partial_settled then settled status', function (): void {
    $user = makeClaimsInsuranceUser([
        'claims.insurance.create',
        'claims.insurance.update-status',
    ]);
    $context = createPatientAndInvoiceForClaim(90000);
    $claim = createClaimCase($user, $context['invoiceId']);
    approveClaimCase($user, (string) $claim['id'], 80000);

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claim['id'].'/reconciliation', [
            'settledAmount' => 30000,
            'settlementReference' => 'PAY-REF-001',
            'reconciliationNotes' => 'First insurer settlement tranche',
        ])
        ->assertOk()
        ->assertJsonPath('data.settledAmount', '30000.00')
        ->assertJsonPath('data.reconciliationStatus', 'partial_settled')
        ->assertJsonPath('data.reconciliationShortfallAmount', '50000.00')
        ->assertJsonPath('data.reconciliationExceptionStatus', 'open')
        ->assertJsonPath('data.reconciliationFollowUpStatus', 'pending');

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claim['id'].'/reconciliation', [
            'settledAmount' => 80000,
            'settlementReference' => 'PAY-REF-002',
            'reconciliationNotes' => 'Final insurer settlement',
        ])
        ->assertOk()
        ->assertJsonPath('data.settledAmount', '80000.00')
        ->assertJsonPath('data.reconciliationStatus', 'settled')
        ->assertJsonPath('data.reconciliationShortfallAmount', '0.00')
        ->assertJsonPath('data.reconciliationExceptionStatus', 'resolved')
        ->assertJsonPath('data.reconciliationFollowUpStatus', 'resolved');
});

it('updates reconciliation follow-up workflow for open exceptions', function (): void {
    $user = makeClaimsInsuranceUser([
        'claims.insurance.create',
        'claims.insurance.update-status',
    ]);
    $context = createPatientAndInvoiceForClaim(88000);
    $claim = createClaimCase($user, $context['invoiceId']);
    approveClaimCase($user, (string) $claim['id'], 80000);

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claim['id'].'/reconciliation', [
            'settledAmount' => 50000,
            'settlementReference' => 'PARTIAL-SETTLEMENT-01',
        ])
        ->assertOk()
        ->assertJsonPath('data.reconciliationExceptionStatus', 'open')
        ->assertJsonPath('data.reconciliationFollowUpStatus', 'pending');

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claim['id'].'/reconciliation-follow-up', [
            'followUpStatus' => 'in_progress',
            'followUpDueAt' => now()->addDays(7)->toDateTimeString(),
            'followUpNote' => 'Payer escalation ticket opened',
        ])
        ->assertOk()
        ->assertJsonPath('data.reconciliationExceptionStatus', 'open')
        ->assertJsonPath('data.reconciliationFollowUpStatus', 'in_progress')
        ->assertJsonPath('data.reconciliationFollowUpNote', 'Payer escalation ticket opened');

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claim['id'].'/reconciliation-follow-up', [
            'followUpStatus' => 'resolved',
            'followUpNote' => 'Finance confirmed recovered balance',
        ])
        ->assertOk()
        ->assertJsonPath('data.reconciliationExceptionStatus', 'resolved')
        ->assertJsonPath('data.reconciliationFollowUpStatus', 'resolved')
        ->assertJsonPath('data.reconciliationFollowUpDueAt', null);
});

it('rejects reconciliation follow-up update when no open exception exists', function (): void {
    $user = makeClaimsInsuranceUser([
        'claims.insurance.create',
        'claims.insurance.update-status',
    ]);
    $context = createPatientAndInvoiceForClaim(45000);
    $claim = createClaimCase($user, $context['invoiceId']);
    approveClaimCase($user, (string) $claim['id'], 45000);

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claim['id'].'/reconciliation', [
            'settledAmount' => 45000,
            'settlementReference' => 'FULL-SETTLEMENT-01',
        ])
        ->assertOk()
        ->assertJsonPath('data.reconciliationExceptionStatus', 'none');

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claim['id'].'/reconciliation-follow-up', [
            'followUpStatus' => 'in_progress',
            'followUpDueAt' => now()->addDays(3)->toDateTimeString(),
            'followUpNote' => 'Should not be allowed for non-open exception',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['followUpStatus']);
});

it('rejects reconciliation when claim is not adjudicated approved or partial', function (): void {
    $user = makeClaimsInsuranceUser([
        'claims.insurance.create',
        'claims.insurance.update-status',
    ]);
    $context = createPatientAndInvoiceForClaim(60000);
    $claim = createClaimCase($user, $context['invoiceId']);

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claim['id'].'/reconciliation', [
            'settledAmount' => 10000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('rejects reconciliation amount above approved amount', function (): void {
    $user = makeClaimsInsuranceUser([
        'claims.insurance.create',
        'claims.insurance.update-status',
    ]);
    $context = createPatientAndInvoiceForClaim(50000);
    $claim = createClaimCase($user, $context['invoiceId']);
    approveClaimCase($user, (string) $claim['id'], 20000);

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claim['id'].'/reconciliation', [
            'settledAmount' => 25000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settledAmount']);
});

it('filters claims queue by reconciliation status', function (): void {
    $user = makeClaimsInsuranceUser([
        'claims.insurance.create',
        'claims.insurance.read',
        'claims.insurance.update-status',
    ]);

    $first = createPatientAndInvoiceForClaim(70000);
    $second = createPatientAndInvoiceForClaim(88000);

    $settled = createClaimCase($user, $first['invoiceId']);
    $pending = createClaimCase($user, $second['invoiceId']);

    approveClaimCase($user, (string) $settled['id'], 70000);
    approveClaimCase($user, (string) $pending['id'], 88000);

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$settled['id'].'/reconciliation', [
            'settledAmount' => 70000,
            'settlementReference' => 'SETTLED-REF-01',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/claims-insurance?reconciliationStatus=settled')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $settled['id']);
});

it('filters claims queue by reconciliation exception status', function (): void {
    $user = makeClaimsInsuranceUser([
        'claims.insurance.create',
        'claims.insurance.read',
        'claims.insurance.update-status',
    ]);

    $first = createPatientAndInvoiceForClaim(70000);
    $second = createPatientAndInvoiceForClaim(76000);

    $openExceptionClaim = createClaimCase($user, $first['invoiceId']);
    $resolvedExceptionClaim = createClaimCase($user, $second['invoiceId']);

    approveClaimCase($user, (string) $openExceptionClaim['id'], 70000);
    approveClaimCase($user, (string) $resolvedExceptionClaim['id'], 76000);

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$openExceptionClaim['id'].'/reconciliation', [
            'settledAmount' => 50000,
            'settlementReference' => 'OPEN-EXC-REF',
        ])
        ->assertOk()
        ->assertJsonPath('data.reconciliationExceptionStatus', 'open');

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$resolvedExceptionClaim['id'].'/reconciliation', [
            'settledAmount' => 30000,
            'settlementReference' => 'RESOLVED-EXC-PARTIAL-REF',
        ])
        ->assertOk()
        ->assertJsonPath('data.reconciliationExceptionStatus', 'open');

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$resolvedExceptionClaim['id'].'/reconciliation', [
            'settledAmount' => 76000,
            'settlementReference' => 'RESOLVED-EXC-FINAL-REF',
        ])
        ->assertOk()
        ->assertJsonPath('data.reconciliationExceptionStatus', 'resolved');

    $this->actingAs($user)
        ->getJson('/api/v1/claims-insurance?reconciliationExceptionStatus=open')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $openExceptionClaim['id']);
});

it('writes claims status transition parity metadata in audit logs', function (): void {
    $user = makeClaimsInsuranceUser([
        'claims.insurance.create',
        'claims.insurance.update-status',
        'claims.insurance.view-audit-logs',
    ]);

    $context = createPatientAndInvoiceForClaim(99000);
    $claim = createClaimCase($user, $context['invoiceId']);

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claim['id'].'/status', [
            'status' => 'partial',
            'reason' => 'Payer approved partial balance only',
            'decisionReason' => 'Policy cap applied to requested services.',
            'adjudicatedAt' => now()->toDateTimeString(),
            'approvedAmount' => 60000,
            'rejectedAmount' => 39000,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'partial');

    $statusAudit = ClaimsInsuranceCaseAuditLogModel::query()
        ->where('claims_insurance_case_id', $claim['id'])
        ->where('action', 'claims-insurance-case.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();

    $metadata = $statusAudit?->metadata ?? [];
    expect($metadata['transition'] ?? [])->toMatchArray([
        'from' => 'draft',
        'to' => 'partial',
    ]);
    expect($metadata)->toMatchArray([
        'reason_required' => true,
        'reason_provided' => true,
        'decision_reason_required' => true,
        'decision_reason_provided' => true,
        'submitted_timestamp_required' => false,
        'submitted_timestamp_provided' => false,
        'adjudicated_timestamp_required' => true,
        'adjudicated_timestamp_provided' => true,
        'approved_amount_required' => true,
        'approved_amount_provided' => true,
        'rejected_amount_required' => true,
        'rejected_amount_provided' => true,
    ]);

    ClaimsInsuranceCaseAuditLogModel::query()->create([
        'claims_insurance_case_id' => $claim['id'],
        'action' => 'claims-insurance-case.document.pdf.downloaded',
        'actor_id' => $user->id,
        'changes' => [],
        'metadata' => [
            'document_filename' => 'afyanova_claim.pdf',
            'request_ip' => '203.0.113.11',
        ],
        'created_at' => now()->addSecond(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/claims-insurance/'.$claim['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.action', 'claims-insurance-case.document.pdf.downloaded')
        ->assertJsonPath('data.0.actionLabel', 'PDF Downloaded')
        ->assertJsonPath('data.0.actor.id', $user->id)
        ->assertJsonPath('data.1.action', 'claims-insurance-case.status.updated');

    $response = $this->actingAs($user)
        ->get('/api/v1/claims-insurance/'.$claim['id'].'/audit-logs/export');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
});

it('writes claims reconciliation transition parity metadata in audit logs', function (): void {
    $user = makeClaimsInsuranceUser([
        'claims.insurance.create',
        'claims.insurance.update-status',
    ]);

    $context = createPatientAndInvoiceForClaim(92000);
    $claim = createClaimCase($user, $context['invoiceId']);
    approveClaimCase($user, (string) $claim['id'], 80000);

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claim['id'].'/reconciliation', [
            'settledAmount' => 30000,
            'settlementReference' => 'HARDENING-REC-001',
            'reconciliationNotes' => 'First tranche posted by payer.',
        ])
        ->assertOk()
        ->assertJsonPath('data.reconciliationStatus', 'partial_settled')
        ->assertJsonPath('data.reconciliationExceptionStatus', 'open');

    $reconciliationAudit = ClaimsInsuranceCaseAuditLogModel::query()
        ->where('claims_insurance_case_id', $claim['id'])
        ->where('action', 'claims-insurance-case.reconciliation.updated')
        ->latest('created_at')
        ->first();

    expect($reconciliationAudit)->not->toBeNull();

    $metadata = $reconciliationAudit?->metadata ?? [];
    expect($metadata['reconciliation_transition'] ?? [])->toMatchArray([
        'from' => 'pending',
        'to' => 'partial_settled',
    ]);
    expect($metadata['exception_transition'] ?? [])->toMatchArray([
        'from' => 'none',
        'to' => 'open',
    ]);
    expect($metadata['follow_up_transition'] ?? [])->toMatchArray([
        'from' => 'none',
        'to' => 'pending',
    ]);
    expect($metadata)->toMatchArray([
        'adjudication_status' => 'approved',
        'adjudication_status_eligible' => true,
        'settled_timestamp_required' => true,
        'settled_timestamp_provided' => true,
        'settlement_reference_provided' => true,
        'reconciliation_notes_provided' => true,
    ]);
});

it('rejects claim detail update when status or reconciliation lifecycle fields are provided', function (): void {
    $user = makeClaimsInsuranceUser([
        'claims.insurance.create',
        'claims.insurance.update',
    ]);

    $context = createPatientAndInvoiceForClaim(54000);
    $claim = createClaimCase($user, $context['invoiceId']);

    $this->actingAs($user)
        ->patchJson('/api/v1/claims-insurance/'.$claim['id'], [
            'payerName' => 'NHIF Updated',
            'status' => 'approved',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});
