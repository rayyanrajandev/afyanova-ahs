<?php

use App\Models\User;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceAuditLogModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoicePaymentModel;
use App\Modules\Billing\Infrastructure\Models\BillingPayerContractModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Support\Settings\SystemSettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function makeBillingPrintActor(array $permissions = []): User
{
    return makeUserWithRole($permissions);
}

function makeBillingPrintPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-PRINT-0001',
        'first_name' => 'Amina',
        'middle_name' => null,
        'last_name' => 'Moshi',
        'gender' => 'female',
        'date_of_birth' => '1996-04-21',
        'phone' => '+255700000001',
        'email' => 'amina@example.test',
        'national_id' => null,
        'country_code' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Ilala',
        'address_line' => 'Upanga',
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);
}

function makeBillingPrintAppointment(string $patientId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT-PRINT-0001',
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => '2026-04-09 09:00:00',
        'duration_minutes' => 30,
        'reason' => 'Clinical review',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);
}

function makeBillingPrintAdmission(string $patientId): AdmissionModel
{
    return AdmissionModel::query()->create([
        'admission_number' => 'ADM-PRINT-0001',
        'patient_id' => $patientId,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward A',
        'bed' => 'A-02',
        'admitted_at' => '2026-04-08 16:30:00',
        'discharged_at' => null,
        'admission_reason' => 'Observation',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);
}

function makeBillingPrintPayer(): BillingPayerContractModel
{
    return BillingPayerContractModel::query()->create([
        'contract_code' => 'NHIF-PRINT',
        'contract_name' => 'NHIF Standard',
        'payer_type' => 'insurance',
        'payer_name' => 'NHIF',
        'payer_plan_code' => 'STD',
        'payer_plan_name' => 'Standard Cover',
        'currency_code' => 'TZS',
        'default_coverage_percent' => 80,
        'default_copay_type' => 'percentage',
        'default_copay_value' => 20,
        'requires_pre_authorization' => false,
        'claim_submission_deadline_days' => 30,
        'settlement_cycle_days' => 14,
        'effective_from' => '2026-01-01 00:00:00',
        'effective_to' => null,
        'terms_and_notes' => null,
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);
}

function makeBillingPrintInvoice(
    PatientModel $patient,
    AppointmentModel $appointment,
    AdmissionModel $admission,
    BillingPayerContractModel $payer,
    User $issuer,
): BillingInvoiceModel {
    return BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV-PRINT-0001',
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'admission_id' => $admission->id,
        'billing_payer_contract_id' => $payer->id,
        'issued_by_user_id' => $issuer->id,
        'invoice_date' => '2026-04-09 10:00:00',
        'currency_code' => 'TZS',
        'subtotal_amount' => 120000,
        'discount_amount' => 5000,
        'tax_amount' => 18000,
        'total_amount' => 133000,
        'paid_amount' => 30000,
        'last_payment_at' => '2026-04-09 11:15:00',
        'last_payment_payer_type' => 'cash',
        'last_payment_method' => 'cash',
        'last_payment_reference' => 'RCPT-0001',
        'balance_amount' => 103000,
        'payment_due_at' => '2026-04-16 23:59:59',
        'notes' => 'Carry this document to the cashier desk for settlement follow-up.',
        'line_items' => [
            [
                'description' => 'Consultation',
                'quantity' => 1,
                'unitPrice' => 50000,
                'lineTotal' => 50000,
                'serviceCode' => 'CONS-01',
                'unit' => 'visit',
                'notes' => 'Senior clinic review',
            ],
            [
                'description' => 'Laboratory panel',
                'quantity' => 1,
                'unitPrice' => 70000,
                'lineTotal' => 70000,
                'serviceCode' => 'LAB-02',
                'unit' => 'panel',
                'notes' => null,
            ],
        ],
        'pricing_mode' => 'catalog',
        'status' => 'partially_paid',
        'status_reason' => 'Awaiting insurer settlement.',
    ]);
}

it('forbids the billing invoice print page without billing invoice read permission', function (): void {
    $actor = makeBillingPrintActor();
    $patient = makeBillingPrintPatient();
    $appointment = makeBillingPrintAppointment($patient->id);
    $admission = makeBillingPrintAdmission($patient->id);
    $payer = makeBillingPrintPayer();
    $issuer = makeBillingPrintActor();
    $invoice = makeBillingPrintInvoice($patient, $appointment, $admission, $payer, $issuer);

    $this->actingAs($actor)
        ->get('/billing-invoices/'.$invoice->id.'/print')
        ->assertForbidden();
});

it('renders a branded billing invoice print page with linked context and payment history', function (): void {
    $actor = makeBillingPrintActor([
        'billing.invoices.read',
        'billing.payments.view-history',
    ]);
    $issuer = makeBillingPrintActor();
    $patient = makeBillingPrintPatient();
    $appointment = makeBillingPrintAppointment($patient->id);
    $admission = makeBillingPrintAdmission($patient->id);
    $payer = makeBillingPrintPayer();
    $invoice = makeBillingPrintInvoice($patient, $appointment, $admission, $payer, $issuer);

    BillingInvoicePaymentModel::query()->create([
        'billing_invoice_id' => $invoice->id,
        'recorded_by_user_id' => $actor->id,
        'payment_at' => '2026-04-09 11:15:00',
        'amount' => 30000,
        'cumulative_paid_amount' => 30000,
        'entry_type' => 'payment',
        'reversal_of_payment_id' => null,
        'reversal_reason' => null,
        'approval_case_reference' => null,
        'payer_type' => 'cash',
        'payment_method' => 'cash',
        'payment_reference' => 'RCPT-0001',
        'source_action' => 'billing-invoice.payment.recorded',
        'note' => 'Collected at front desk.',
    ]);

    app(SystemSettingsManager::class)->putMany([
        'branding.system_name' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Afyanova Cloud',
        ],
        'branding.mail_from_name' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Afyanova Billing Desk',
        ],
        'branding.mail_from_address' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'billing@afyanova.so',
        ],
        'branding.mail_reply_to_address' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'support@afyanova.so',
        ],
        'branding.mail_footer_text' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Need help? Contact Afyanova support.',
        ],
    ]);

    $this->actingAs($actor)
        ->get('/billing-invoices/'.$invoice->id.'/print')
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing-invoices/Print')
            ->where('invoice.id', (string) $invoice->id)
            ->where('invoice.invoiceNumber', 'INV-PRINT-0001')
            ->where('patient.patientNumber', 'PT-PRINT-0001')
            ->where('patient.fullName', 'Amina Moshi')
            ->where('appointment.appointmentNumber', 'APT-PRINT-0001')
            ->where('admission.admissionNumber', 'ADM-PRINT-0001')
            ->where('payer.payerName', 'NHIF')
            ->where('issuedBy.name', $issuer->name)
            ->where('canViewPaymentHistory', true)
            ->where('payments.0.paymentMethod', 'cash')
            ->where('payments.0.paymentReference', 'RCPT-0001')
            ->where('documentBranding.systemName', 'Afyanova Cloud')
            ->where('documentBranding.issuedByName', 'Afyanova Billing Desk')
            ->where('documentBranding.supportEmail', 'support@afyanova.so')
            ->where('documentBranding.footerText', 'Need help? Contact Afyanova support.')
            ->where('generatedAt', fn (string $value): bool => $value !== ''));
});

it('omits invoice payment history on the print page when the actor lacks history permission', function (): void {
    $actor = makeBillingPrintActor([
        'billing.invoices.read',
    ]);
    $issuer = makeBillingPrintActor();
    $patient = makeBillingPrintPatient();
    $appointment = makeBillingPrintAppointment($patient->id);
    $admission = makeBillingPrintAdmission($patient->id);
    $payer = makeBillingPrintPayer();
    $invoice = makeBillingPrintInvoice($patient, $appointment, $admission, $payer, $issuer);

    BillingInvoicePaymentModel::query()->create([
        'billing_invoice_id' => $invoice->id,
        'recorded_by_user_id' => $issuer->id,
        'payment_at' => '2026-04-09 11:15:00',
        'amount' => 30000,
        'cumulative_paid_amount' => 30000,
        'entry_type' => 'payment',
        'reversal_of_payment_id' => null,
        'reversal_reason' => null,
        'approval_case_reference' => null,
        'payer_type' => 'cash',
        'payment_method' => 'cash',
        'payment_reference' => 'RCPT-0001',
        'source_action' => 'billing-invoice.payment.recorded',
        'note' => 'Collected at front desk.',
    ]);

    $this->actingAs($actor)
        ->get('/billing-invoices/'.$invoice->id.'/print')
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing-invoices/Print')
            ->where('canViewPaymentHistory', false)
            ->where('payments', []));
});

it('downloads the billing invoice as a branded pdf when authorized', function (): void {
    $actor = makeBillingPrintActor(['billing.invoices.read']);
    $issuer = makeBillingPrintActor();
    $patient = makeBillingPrintPatient();
    $appointment = makeBillingPrintAppointment($patient->id);
    $admission = makeBillingPrintAdmission($patient->id);
    $payer = makeBillingPrintPayer();
    $invoice = makeBillingPrintInvoice($patient, $appointment, $admission, $payer, $issuer);

    $response = $this->actingAs($actor)
        ->withHeader('User-Agent', 'Afyanova-Test-Agent/1.0')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->get('/billing-invoices/'.$invoice->id.'/pdf');

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Document-Format', 'pdf')
        ->assertHeader('X-Document-Schema-Version', 'document-pdf.v1')
        ->assertHeader('X-Document-Source', 'billing-invoice');

    expect((string) $response->headers->get('Content-Disposition'))
        ->toContain('.pdf');
    expect(substr((string) $response->getContent(), 0, 4))
        ->toBe('%PDF');

    $auditLog = BillingInvoiceAuditLogModel::query()
        ->where('billing_invoice_id', $invoice->id)
        ->latest('created_at')
        ->first();

    expect($auditLog)->not->toBeNull();
    expect($auditLog?->action)->toBe('billing-invoice.document.pdf.downloaded');
    expect($auditLog?->actor_id)->toBe($actor->id);
    expect($auditLog?->metadata['document_format'] ?? null)->toBe('pdf');
    expect($auditLog?->metadata['document_delivery'] ?? null)->toBe('download');
    expect($auditLog?->metadata['document_schema_version'] ?? null)->toBe('document-pdf.v1');
    expect($auditLog?->metadata['document_source'] ?? null)->toBe('billing-invoice');
    expect($auditLog?->metadata['document_source_id'] ?? null)->toBe($invoice->id);
    expect($auditLog?->metadata['document_number'] ?? null)->toBe('INV-PRINT-0001');
    expect($auditLog?->metadata['route_name'] ?? null)->toBe('billing-invoices.pdf.download');
    expect($auditLog?->metadata['request_path'] ?? null)->toBe('/billing-invoices/'.$invoice->id.'/pdf');
    expect($auditLog?->metadata['request_ip'] ?? null)->toBe('203.0.113.10');
    expect($auditLog?->metadata['user_agent'] ?? null)->toBe('Afyanova-Test-Agent/1.0');
    expect($auditLog?->metadata['document_filename'] ?? '')->toContain('.pdf');
    expect($auditLog?->metadata['generated_at'] ?? null)->not->toBeNull();
});
