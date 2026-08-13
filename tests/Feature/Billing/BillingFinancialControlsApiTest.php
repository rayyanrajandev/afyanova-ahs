<?php

use App\Models\User;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\GLJournalEntryModel;
use App\Modules\Billing\Infrastructure\Models\RevenueRecognitionModel;
use App\Modules\ClaimsInsurance\Infrastructure\Models\ClaimsInsuranceCaseModel;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeBillingFinancialControlsUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @return array{id: string, patientId: string}
 */
function createFinancialControlsInvoice(
    string $status,
    float $totalAmount,
    float $paidAmount,
    ?string $paymentDueAt,
    ?string $invoiceDate = null,
    string $currencyCode = 'TZS',
    array $lineItems = [],
): array {
    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-'.strtoupper(Str::random(8)),
        'first_name' => 'Finance',
        'last_name' => 'Patient',
        'gender' => 'female',
        'date_of_birth' => '1990-05-15',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $normalizedTotal = round(max($totalAmount, 0), 2);
    $normalizedPaid = round(max(min($paidAmount, $normalizedTotal), 0), 2);
    $balance = round(max($normalizedTotal - $normalizedPaid, 0), 2);

    $invoice = BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
        'patient_id' => $patient->id,
        'invoice_date' => $invoiceDate ?? now()->toDateTimeString(),
        'currency_code' => $currencyCode,
        'subtotal_amount' => $normalizedTotal,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => $normalizedTotal,
        'paid_amount' => $normalizedPaid,
        'balance_amount' => $balance,
        'payment_due_at' => $paymentDueAt,
        'line_items' => $lineItems,
        'status' => $status,
    ]);

    return [
        'id' => (string) $invoice->id,
        'patientId' => (string) $patient->id,
    ];
}

function createFinancialControlsClaimCase(
    string $invoiceId,
    string $patientId,
    string $status,
    float $approvedAmount,
    float $rejectedAmount,
    float $settledAmount,
    string $reconciliationStatus,
    ?string $decisionReason = null,
    ?string $submittedAt = null,
): void {
    ClaimsInsuranceCaseModel::query()->create([
        'claim_number' => 'CLM-'.strtoupper(Str::random(10)),
        'invoice_id' => $invoiceId,
        'patient_id' => $patientId,
        'payer_type' => 'insurance',
        'payer_name' => 'NHIF',
        'claim_amount' => round(max($approvedAmount + $rejectedAmount, 0), 2),
        'currency_code' => 'TZS',
        'submitted_at' => $submittedAt ?? now()->toDateTimeString(),
        'adjudicated_at' => now()->toDateTimeString(),
        'approved_amount' => round(max($approvedAmount, 0), 2),
        'rejected_amount' => round(max($rejectedAmount, 0), 2),
        'settled_amount' => round(max($settledAmount, 0), 2),
        'settled_at' => $settledAmount > 0 ? now()->toDateTimeString() : null,
        'decision_reason' => $decisionReason,
        'status' => $status,
        'reconciliation_status' => $reconciliationStatus,
    ]);
}

function createRevenueRecognitionRecord(
    string $invoiceId,
    float $recognizedAmount,
    float $adjustedAmount,
    float $netRevenue,
    string $method = 'accrual',
    ?string $recognitionDate = null,
): string {
    $record = RevenueRecognitionModel::query()->create([
        'billing_invoice_id' => $invoiceId,
        'recognition_date' => $recognitionDate ?? now()->toDateTimeString(),
        'recognition_method' => $method,
        'amount_recognized' => round($recognizedAmount, 2),
        'amount_adjusted' => round($adjustedAmount, 2),
        'net_revenue' => round($netRevenue, 2),
        'gl_entry_ids' => [],
        'notes' => 'Generated in test',
    ]);

    return (string) $record->id;
}

function createGlJournalEntry(
    string $referenceId,
    string $referenceType,
    string $status,
    float $debitAmount,
    float $creditAmount,
    ?string $postingDate = null,
    ?string $entryDate = null,
    ?string $batchId = null,
): void {
    GLJournalEntryModel::query()->create([
        'tenant_id' => (string) Str::uuid(),
        'facility_id' => (string) Str::uuid(),
        'reference_id' => $referenceId,
        'reference_type' => $referenceType,
        'account_code' => '4000',
        'account_name' => 'Billing Revenue',
        'debit_amount' => round($debitAmount, 2),
        'credit_amount' => round($creditAmount, 2),
        'entry_date' => $entryDate ?? now()->toDateTimeString(),
        'posting_date' => $postingDate,
        'description' => 'Generated in test',
        'status' => $status,
        'batch_id' => $batchId,
    ]);
}

it('requires authentication for billing financial controls summary', function (): void {
    $this->getJson('/api/v1/billing-invoices/financial-controls/summary')
        ->assertUnauthorized();
});

it('forbids billing financial controls summary without permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/billing-invoices/financial-controls/summary')
        ->assertForbidden();
});

it('requires authentication for billing financial controls summary export', function (): void {
    $this->getJson('/api/v1/billing-invoices/financial-controls/summary/export')
        ->assertUnauthorized();
});

it('requires authentication for billing revenue recognition summary', function (): void {
    $this->getJson('/api/v1/billing-invoices/financial-controls/revenue-recognition-summary')
        ->assertUnauthorized();
});

it('forbids billing financial controls summary export without permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/billing-invoices/financial-controls/summary/export')
        ->assertForbidden();
});

it('forbids billing revenue recognition summary without permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/billing-invoices/financial-controls/revenue-recognition-summary')
        ->assertForbidden();
});

it('returns billing financial controls summary with aging denied outstanding and settlement metrics', function (): void {
    $user = makeBillingFinancialControlsUser(['billing.financial-controls.read']);

    $invoiceA = createFinancialControlsInvoice(
        status: 'issued',
        totalAmount: 100,
        paidAmount: 0,
        paymentDueAt: now()->subDays(40)->toDateTimeString(),
    );
    $invoiceB = createFinancialControlsInvoice(
        status: 'partially_paid',
        totalAmount: 300,
        paidAmount: 100,
        paymentDueAt: now()->subDays(10)->toDateTimeString(),
    );
    $invoiceC = createFinancialControlsInvoice(
        status: 'issued',
        totalAmount: 150,
        paidAmount: 0,
        paymentDueAt: now()->addDays(5)->toDateTimeString(),
    );

    // Excluded from outstanding balances by status.
    createFinancialControlsInvoice(
        status: 'cancelled',
        totalAmount: 500,
        paidAmount: 0,
        paymentDueAt: now()->subDays(120)->toDateTimeString(),
    );

    createFinancialControlsClaimCase(
        invoiceId: $invoiceA['id'],
        patientId: $invoiceA['patientId'],
        status: 'rejected',
        approvedAmount: 0,
        rejectedAmount: 120,
        settledAmount: 0,
        reconciliationStatus: 'pending',
        decisionReason: 'Missing pre-authorization',
    );
    createFinancialControlsClaimCase(
        invoiceId: $invoiceB['id'],
        patientId: $invoiceB['patientId'],
        status: 'partial',
        approvedAmount: 400,
        rejectedAmount: 100,
        settledAmount: 150,
        reconciliationStatus: 'partial_settled',
        decisionReason: 'Tariff cap applied',
    );
    createFinancialControlsClaimCase(
        invoiceId: $invoiceC['id'],
        patientId: $invoiceC['patientId'],
        status: 'approved',
        approvedAmount: 500,
        rejectedAmount: 0,
        settledAmount: 500,
        reconciliationStatus: 'settled',
        decisionReason: null,
    );
    createFinancialControlsClaimCase(
        invoiceId: $invoiceC['id'],
        patientId: $invoiceC['patientId'],
        status: 'approved',
        approvedAmount: 300,
        rejectedAmount: 0,
        settledAmount: 0,
        reconciliationStatus: 'pending',
        decisionReason: null,
    );

    $this->actingAs($user)
        ->getJson('/api/v1/billing-invoices/financial-controls/summary')
        ->assertOk()
        ->assertJsonPath('data.outstanding.invoiceCount', 3)
        ->assertJsonPath('data.outstanding.balanceAmountTotal', 450)
        ->assertJsonPath('data.outstanding.overdueInvoiceCount', 2)
        ->assertJsonPath('data.outstanding.overdueBalanceAmountTotal', 300)
        ->assertJsonPath('data.agingBuckets.current.balanceAmountTotal', 150)
        ->assertJsonPath('data.agingBuckets.days_1_30.balanceAmountTotal', 200)
        ->assertJsonPath('data.agingBuckets.days_31_60.balanceAmountTotal', 100)
        ->assertJsonPath('data.denials.deniedClaimCount', 1)
        ->assertJsonPath('data.denials.partialDeniedClaimCount', 1)
        ->assertJsonPath('data.denials.deniedAmountTotal', 220)
        ->assertJsonPath('data.settlement.approvedAmountTotal', 1200)
        ->assertJsonPath('data.settlement.settledAmountTotal', 650)
        ->assertJsonPath('data.settlement.pendingSettlementAmount', 550)
        ->assertJsonPath('data.settlement.settlementRatePercent', 54.17)
        ->assertJsonPath('data.settlement.reconciliationStatusCounts.pending', 1)
        ->assertJsonPath('data.settlement.reconciliationStatusCounts.partial_settled', 1)
        ->assertJsonPath('data.settlement.reconciliationStatusCounts.settled', 1)
        ->assertJsonPath('data.settlement.reconciliationStatusCounts.total', 3);
});

it('exports billing financial controls summary as csv', function (): void {
    $user = makeBillingFinancialControlsUser(['billing.financial-controls.read']);

    $invoice = createFinancialControlsInvoice(
        status: 'issued',
        totalAmount: 250,
        paidAmount: 50,
        paymentDueAt: now()->subDays(20)->toDateTimeString(),
    );

    createFinancialControlsClaimCase(
        invoiceId: $invoice['id'],
        patientId: $invoice['patientId'],
        status: 'partial',
        approvedAmount: 180,
        rejectedAmount: 20,
        settledAmount: 100,
        reconciliationStatus: 'partial_settled',
        decisionReason: 'Tariff cap applied',
    );

    $response = $this->actingAs($user)
        ->get('/api/v1/billing-invoices/financial-controls/summary/export');

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('X-Billing-Financial-Controls-CSV-Schema-Version', 'billing-financial-controls-csv.v1')
        ->assertHeader('X-Export-System-Name', 'Afyanova AHS')
        ->assertHeader('X-Export-System-Slug', 'afyanova_ahs');

    $content = $response->streamedContent();

    expect($content)->toContain('path,value');
    expect($content)->toContain('outstanding.invoiceCount');
    expect($content)->toContain('agingBuckets.days_1_30.balanceAmountTotal');
    expect($content)->toContain('denials.deniedAmountTotal');
    expect($content)->toContain('settlement.settlementRatePercent');
    expect((string) $response->headers->get('content-disposition'))
        ->toContain('afyanova_ahs_billing_financial_controls_summary_');
});

it('returns billing revenue recognition and gl posting summary', function (): void {
    $user = makeBillingFinancialControlsUser(['billing.financial-controls.read']);

    $recognizedInvoice = createFinancialControlsInvoice(
        status: 'paid',
        totalAmount: 1200,
        paidAmount: 1200,
        paymentDueAt: now()->addDays(2)->toDateTimeString(),
        invoiceDate: now()->subDays(2)->toDateTimeString(),
    );
    $pendingRecognitionInvoice = createFinancialControlsInvoice(
        status: 'issued',
        totalAmount: 450,
        paidAmount: 0,
        paymentDueAt: now()->addDays(7)->toDateTimeString(),
        invoiceDate: now()->subDay()->toDateTimeString(),
    );
    createFinancialControlsInvoice(
        status: 'draft',
        totalAmount: 999,
        paidAmount: 0,
        paymentDueAt: now()->addDays(10)->toDateTimeString(),
        invoiceDate: now()->subDay()->toDateTimeString(),
    );

    createRevenueRecognitionRecord(
        invoiceId: $recognizedInvoice['id'],
        recognizedAmount: 1200,
        adjustedAmount: 50,
        netRevenue: 1150,
        method: 'accrual',
        recognitionDate: now()->subDay()->toDateTimeString(),
    );

    createGlJournalEntry(
        referenceId: $recognizedInvoice['id'],
        referenceType: 'revenue_recognition',
        status: 'posted',
        debitAmount: 1200,
        creditAmount: 1200,
        postingDate: now()->toDateTimeString(),
        entryDate: now()->subDay()->toDateTimeString(),
        batchId: (string) Str::uuid(),
    );
    createGlJournalEntry(
        referenceId: $pendingRecognitionInvoice['id'],
        referenceType: 'invoice',
        status: 'draft',
        debitAmount: 450,
        creditAmount: 450,
        postingDate: null,
        entryDate: now()->subDays(5)->toDateTimeString(),
        batchId: (string) Str::uuid(),
    );
    createGlJournalEntry(
        referenceId: $pendingRecognitionInvoice['id'],
        referenceType: 'invoice',
        status: 'reversed',
        debitAmount: 100,
        creditAmount: 100,
        postingDate: now()->subDays(2)->toDateTimeString(),
        entryDate: now()->subDays(3)->toDateTimeString(),
        batchId: null,
    );

    $this->actingAs($user)
        ->getJson('/api/v1/billing-invoices/financial-controls/revenue-recognition-summary')
        ->assertOk()
        ->assertJsonPath('data.infrastructure.revenueRecognitionReady', true)
        ->assertJsonPath('data.infrastructure.glPostingReady', true)
        ->assertJsonPath('data.recognition.recognizedInvoiceCount', 1)
        ->assertJsonPath('data.recognition.recognizedAmountTotal', 1200)
        ->assertJsonPath('data.recognition.adjustedAmountTotal', 50)
        ->assertJsonPath('data.recognition.netRevenueTotal', 1150)
        ->assertJsonPath('data.recognition.recognitionMethodCounts.accrual', 1)
        ->assertJsonPath('data.coverage.eligibleInvoiceCount', 2)
        ->assertJsonPath('data.coverage.recognizedInvoiceCount', 1)
        ->assertJsonPath('data.coverage.unrecognizedInvoiceCount', 1)
        ->assertJsonPath('data.coverage.recognizedCoveragePercent', 50)
        ->assertJsonPath('data.coverage.unrecognizedAmountTotal', 450)
        ->assertJsonPath('data.glPosting.entryStatusCounts.posted', 1)
        ->assertJsonPath('data.glPosting.entryStatusCounts.draft', 1)
        ->assertJsonPath('data.glPosting.entryStatusCounts.reversed', 1)
        ->assertJsonPath('data.glPosting.entryStatusCounts.total', 3)
        ->assertJsonPath('data.glPosting.draftDebitAmountTotal', 450)
        ->assertJsonPath('data.glPosting.postedCreditAmountTotal', 1200)
        ->assertJsonPath('data.glPosting.staleDraftEntryCount', 1)
        ->assertJsonPath('data.glPosting.openBatchCount', 1);
});

it('filters billing finance summaries by department', function (): void {
    $user = makeBillingFinancialControlsUser(['billing.financial-controls.read']);

    $radiology = DepartmentModel::query()->create([
        'code' => 'RAD',
        'name' => 'Radiology',
        'service_type' => 'diagnostics',
        'status' => 'active',
    ]);

    DepartmentModel::query()->create([
        'code' => 'OPD',
        'name' => 'Outpatient',
        'service_type' => 'consultation',
        'status' => 'active',
    ]);

    $radiologyInvoice = createFinancialControlsInvoice(
        status: 'issued',
        totalAmount: 200,
        paidAmount: 0,
        paymentDueAt: now()->subDays(4)->toDateTimeString(),
        lineItems: [[
            'description' => 'Ultrasound',
            'quantity' => 1,
            'unitPrice' => 200,
            'lineTotal' => 200,
            'serviceCode' => 'RAD-001',
            'departmentId' => $radiology->id,
            'department' => 'Radiology',
        ]],
    );

    $outpatientInvoice = createFinancialControlsInvoice(
        status: 'issued',
        totalAmount: 90,
        paidAmount: 0,
        paymentDueAt: now()->subDays(2)->toDateTimeString(),
        lineItems: [[
            'description' => 'Consultation',
            'quantity' => 1,
            'unitPrice' => 90,
            'lineTotal' => 90,
            'serviceCode' => 'CONS-001',
            'department' => 'Outpatient',
        ]],
    );

    createFinancialControlsClaimCase(
        invoiceId: $radiologyInvoice['id'],
        patientId: $radiologyInvoice['patientId'],
        status: 'approved',
        approvedAmount: 180,
        rejectedAmount: 0,
        settledAmount: 100,
        reconciliationStatus: 'partial_settled',
    );
    createFinancialControlsClaimCase(
        invoiceId: $outpatientInvoice['id'],
        patientId: $outpatientInvoice['patientId'],
        status: 'rejected',
        approvedAmount: 0,
        rejectedAmount: 90,
        settledAmount: 0,
        reconciliationStatus: 'pending',
        decisionReason: 'Excluded service',
    );

    createRevenueRecognitionRecord(
        invoiceId: $radiologyInvoice['id'],
        recognizedAmount: 200,
        adjustedAmount: 0,
        netRevenue: 200,
        method: 'accrual',
    );

    createGlJournalEntry(
        referenceId: $radiologyInvoice['id'],
        referenceType: 'invoice',
        status: 'posted',
        debitAmount: 200,
        creditAmount: 200,
        postingDate: now()->toDateTimeString(),
    );
    createGlJournalEntry(
        referenceId: $outpatientInvoice['id'],
        referenceType: 'invoice',
        status: 'draft',
        debitAmount: 90,
        creditAmount: 90,
        postingDate: null,
    );

    $this->actingAs($user)
        ->getJson('/api/v1/billing-invoices/financial-controls/summary?departmentId='.$radiology->id)
        ->assertOk()
        ->assertJsonPath('data.window.departmentId', $radiology->id)
        ->assertJsonPath('data.outstanding.invoiceCount', 1)
        ->assertJsonPath('data.outstanding.balanceAmountTotal', 200)
        ->assertJsonPath('data.denials.deniedClaimCount', 0)
        ->assertJsonPath('data.settlement.approvedAmountTotal', 180)
        ->assertJsonPath('data.settlement.pendingSettlementAmount', 80);

    $this->actingAs($user)
        ->getJson('/api/v1/billing-invoices/financial-controls/revenue-recognition-summary?departmentId='.$radiology->id)
        ->assertOk()
        ->assertJsonPath('data.window.departmentId', $radiology->id)
        ->assertJsonPath('data.coverage.eligibleInvoiceCount', 1)
        ->assertJsonPath('data.coverage.recognizedInvoiceCount', 1)
        ->assertJsonPath('data.coverage.unrecognizedInvoiceCount', 0)
        ->assertJsonPath('data.glPosting.postedDebitAmountTotal', 200)
        ->assertJsonPath('data.glPosting.draftDebitAmountTotal', 0);
});

it('lists billing-relevant finance department filter options with billing financial controls permission', function (): void {
    $user = makeBillingFinancialControlsUser(['billing.financial-controls.read']);

    $radiology = DepartmentModel::query()->create([
        'code' => 'RAD',
        'name' => 'Radiology',
        'service_type' => 'Diagnostic',
        'is_patient_facing' => true,
        'is_appointmentable' => true,
        'status' => 'active',
    ]);
    DepartmentModel::query()->create([
        'code' => 'LAB',
        'name' => 'Laboratory',
        'service_type' => 'Diagnostic',
        'is_patient_facing' => true,
        'is_appointmentable' => true,
        'status' => 'active',
    ]);
    DepartmentModel::query()->create([
        'code' => 'WARD',
        'name' => 'Inpatient Ward',
        'service_type' => 'Clinical',
        'is_patient_facing' => true,
        'is_appointmentable' => false,
        'status' => 'active',
    ]);
    DepartmentModel::query()->create([
        'code' => 'THR',
        'name' => 'Theatre',
        'service_type' => 'Clinical',
        'is_patient_facing' => true,
        'is_appointmentable' => false,
        'status' => 'active',
    ]);
    DepartmentModel::query()->create([
        'code' => 'ADM',
        'name' => 'Administration',
        'service_type' => 'Administrative',
        'is_patient_facing' => false,
        'is_appointmentable' => false,
        'status' => 'active',
    ]);
    DepartmentModel::query()->create([
        'code' => 'FDS',
        'name' => 'Front Desk',
        'service_type' => 'Support',
        'is_patient_facing' => true,
        'is_appointmentable' => false,
        'status' => 'active',
    ]);
    DepartmentModel::query()->create([
        'code' => 'LEG',
        'name' => 'Legacy',
        'service_type' => 'other',
        'is_patient_facing' => true,
        'is_appointmentable' => false,
        'status' => 'inactive',
    ]);
    BillingServiceCatalogItemModel::query()->create([
        'service_code' => 'RAD-OPT-001',
        'service_name' => 'Chest X-Ray',
        'service_type' => 'radiology',
        'department_id' => $radiology->id,
        'department' => 'Radiology',
        'unit' => 'study',
        'base_price' => 45000,
        'currency_code' => 'TZS',
        'tax_rate_percent' => 0,
        'is_taxable' => false,
        'effective_from' => now()->subDay()->toDateTimeString(),
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing-invoices/financial-controls/department-options')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonFragment(['id' => $radiology->id, 'code' => 'RAD', 'name' => 'Radiology'])
        ->assertJsonFragment(['code' => 'LAB', 'name' => 'Laboratory'])
        ->assertJsonFragment(['code' => 'WARD', 'name' => 'Inpatient Ward'])
        ->assertJsonFragment(['code' => 'THR', 'name' => 'Theatre'])
        ->assertJsonMissing(['code' => 'ADM'])
        ->assertJsonMissing(['code' => 'FDS'])
        ->assertJsonMissing(['code' => 'LEG']);
});
