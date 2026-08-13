<?php

use App\Modules\Billing\Domain\Integrations\NhifRemittanceInterface;
use App\Modules\Billing\Infrastructure\Models\BillingNhifClaimSubmissionModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\ClaimsInsurance\Infrastructure\Models\ClaimsInsuranceCaseModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * billing-financial-state-remediation-plan.md, Phase 2: regression coverage for
 * the confirmed bug where NhifRemittanceProcessor::reconcile() updated the claim
 * record but never recorded the settlement as an actual payment against the
 * linked invoice, leaving insurer-settled invoices stuck at issued/partially_paid
 * indefinitely.
 */
uses(RefreshDatabase::class);

function makeNhifSettledInvoice(string $patientId, float $totalAmount): BillingInvoiceModel
{
    return BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'invoice_date' => now(),
        'currency_code' => 'TZS',
        'subtotal_amount' => $totalAmount,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => $totalAmount,
        'paid_amount' => 0,
        'balance_amount' => $totalAmount,
        'status' => 'issued',
    ]);
}

function makeNhifClaimSubmission(BillingInvoiceModel $invoice, string $patientId, string $claimReference, string $tenantId, string $facilityId): BillingNhifClaimSubmissionModel
{
    $case = ClaimsInsuranceCaseModel::query()->create([
        'claim_number' => 'CLM'.strtoupper(Str::random(8)),
        // tenant_id/facility_id are FK-constrained to real tenants/facilities here
        // (unlike billing_nhif_claim_submissions' plain, unconstrained columns
        // below) -- left null, matching this suite's other unscoped fixtures.
        'invoice_id' => $invoice->id,
        'patient_id' => $patientId,
        'payer_type' => 'nhif',
        'payer_name' => 'NHIF',
        'claim_amount' => $invoice->total_amount,
        'currency_code' => 'TZS',
        'status' => 'submitted',
        'reconciliation_status' => 'pending',
    ]);

    return BillingNhifClaimSubmissionModel::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'claims_insurance_case_id' => $case->id,
        'billing_invoice_id' => $invoice->id,
        'nhif_claim_reference' => $claimReference,
        'submission_status' => 'submitted',
        'submitted_amount' => $invoice->total_amount,
        'submitted_at' => now(),
    ]);
}

it('records an invoice payment when NHIF settles a claim, closing the confirmed bug', function (): void {
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();
    $patient = makeBillingPatient();
    $invoice = makeNhifSettledInvoice($patient->id, 25000);
    makeNhifClaimSubmission($invoice, $patient->id, 'CLAIMREF-001', $tenantId, $facilityId);

    app(NhifRemittanceInterface::class)->reconcile(
        remittanceRecords: [[
            'claim_reference' => 'CLAIMREF-001',
            'member_number' => 'MEM-1',
            'patient_name' => 'Test Patient',
            'claimed_amount' => 25000.0,
            'approved_amount' => 25000.0,
            'rejected_amount' => 0.0,
            'settled_amount' => 25000.0,
            'decision' => 'settled',
            'decision_reason' => '',
            'raw' => [],
        ]],
        tenantId: $tenantId,
        facilityId: $facilityId,
    );

    $invoice->refresh();

    expect($invoice->status)->toBe('paid')
        ->and((float) $invoice->paid_amount)->toBe(25000.0)
        ->and((float) $invoice->balance_amount)->toBe(0.0);
});

it('does not throw and leaves the batch intact when the linked invoice is already fully paid', function (): void {
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();
    $patient = makeBillingPatient();
    $invoice = makeNhifSettledInvoice($patient->id, 25000);
    makeNhifClaimSubmission($invoice, $patient->id, 'CLAIMREF-002', $tenantId, $facilityId);

    // Fully pay the invoice out-of-band first, so the settlement below hits the
    // "already paid" guard in RecordBillingInvoicePaymentUseCase.
    $invoice->update(['status' => 'paid', 'paid_amount' => 25000, 'balance_amount' => 0]);

    $result = app(NhifRemittanceInterface::class)->reconcile(
        remittanceRecords: [[
            'claim_reference' => 'CLAIMREF-002',
            'member_number' => 'MEM-2',
            'patient_name' => 'Test Patient',
            'claimed_amount' => 25000.0,
            'approved_amount' => 25000.0,
            'rejected_amount' => 0.0,
            'settled_amount' => 25000.0,
            'decision' => 'settled',
            'decision_reason' => '',
            'raw' => [],
        ]],
        tenantId: $tenantId,
        facilityId: $facilityId,
    );

    expect($result->matchedClaims)->toBe(1);

    $invoice->refresh();
    expect($invoice->status)->toBe('paid')
        ->and((float) $invoice->paid_amount)->toBe(25000.0);
});
