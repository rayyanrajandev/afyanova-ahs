<?php

use App\Models\User;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\ClaimsInsurance\Infrastructure\Models\ClaimsInsuranceCaseAuditLogModel;
use App\Modules\ClaimsInsurance\Infrastructure\Models\ClaimsInsuranceCaseModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Support\Settings\SystemSettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function makeClaimsPrintActor(array $permissions = []): User
{
    return makeUserWithRole($permissions);
}

function makeClaimsPrintPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-CLM-0001',
        'first_name' => 'Neema',
        'middle_name' => null,
        'last_name' => 'John',
        'gender' => 'female',
        'date_of_birth' => '1994-08-12',
        'phone' => '+255700000111',
        'email' => 'neema@example.test',
        'country_code' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Kinondoni',
        'address_line' => 'Mikocheni',
        'status' => 'active',
    ]);
}

function makeClaimsPrintAppointment(string $patientId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT-CLM-0001',
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Radiology',
        'scheduled_at' => '2026-04-09 08:15:00',
        'duration_minutes' => 25,
        'reason' => 'Insurance follow-up imaging',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);
}

function makeClaimsPrintAdmission(string $patientId, ?string $appointmentId = null): AdmissionModel
{
    return AdmissionModel::query()->create([
        'admission_number' => 'ADM-CLM-0001',
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward B',
        'bed' => 'B-04',
        'admitted_at' => '2026-04-08 17:40:00',
        'discharged_at' => null,
        'admission_reason' => 'Short stay observation',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);
}

function makeClaimsPrintInvoice(
    PatientModel $patient,
    ?AppointmentModel $appointment = null,
    ?AdmissionModel $admission = null,
): BillingInvoiceModel {
    return BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV-CLM-0001',
        'patient_id' => $patient->id,
        'appointment_id' => $appointment?->id,
        'admission_id' => $admission?->id,
        'billing_payer_contract_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => '2026-04-09 09:00:00',
        'currency_code' => 'TZS',
        'subtotal_amount' => 91000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 91000,
        'paid_amount' => 20000,
        'last_payment_at' => '2026-04-09 10:05:00',
        'last_payment_payer_type' => 'insurance',
        'last_payment_method' => 'bank_transfer',
        'last_payment_reference' => 'SETTLE-CLM-1',
        'balance_amount' => 71000,
        'payment_due_at' => '2026-04-21 23:59:59',
        'notes' => 'Awaiting adjudication completion.',
        'line_items' => [
            ['description' => 'Imaging', 'quantity' => 1, 'unitPrice' => 55000, 'lineTotal' => 55000],
            ['description' => 'Review', 'quantity' => 1, 'unitPrice' => 36000, 'lineTotal' => 36000],
        ],
        'pricing_mode' => 'catalog',
        'pricing_context' => null,
        'status' => 'partially_paid',
        'status_reason' => 'Balance pending insurer approval.',
    ]);
}

function makeClaimsPrintCase(
    BillingInvoiceModel $invoice,
    PatientModel $patient,
    ?AppointmentModel $appointment = null,
    ?AdmissionModel $admission = null,
    ?User $followUpOwner = null,
): ClaimsInsuranceCaseModel {
    return ClaimsInsuranceCaseModel::query()->create([
        'claim_number' => 'CLM-PRINT-0001',
        'invoice_id' => $invoice->id,
        'patient_id' => $patient->id,
        'appointment_id' => $appointment?->id,
        'admission_id' => $admission?->id,
        'payer_type' => 'insurance',
        'payer_name' => 'NHIF',
        'payer_reference' => 'NHIF-REF-778',
        'claim_amount' => 91000,
        'currency_code' => 'TZS',
        'submitted_at' => '2026-04-09 12:00:00',
        'adjudicated_at' => '2026-04-11 09:30:00',
        'approved_amount' => 80000,
        'rejected_amount' => 11000,
        'settled_amount' => 50000,
        'reconciliation_shortfall_amount' => 30000,
        'settled_at' => '2026-04-12 14:20:00',
        'settlement_reference' => 'NHIF-STL-0009',
        'decision_reason' => 'Policy limit applied to non-covered service.',
        'notes' => 'Finance asked for payer clarification on residual balance.',
        'status' => 'partial',
        'reconciliation_status' => 'partial_settled',
        'reconciliation_exception_status' => 'open',
        'reconciliation_follow_up_status' => 'in_progress',
        'reconciliation_follow_up_due_at' => '2026-04-15 10:00:00',
        'reconciliation_follow_up_note' => 'Escalated to insurer reconciliation desk.',
        'reconciliation_follow_up_updated_at' => '2026-04-12 15:00:00',
        'reconciliation_follow_up_updated_by_user_id' => $followUpOwner?->id,
        'reconciliation_notes' => 'Initial tranche received and posted.',
        'status_reason' => 'Partial approval captured.',
    ]);
}

it('forbids the claims insurance print page without claims insurance read permission', function (): void {
    $actor = makeClaimsPrintActor();
    $patient = makeClaimsPrintPatient();
    $appointment = makeClaimsPrintAppointment($patient->id);
    $admission = makeClaimsPrintAdmission($patient->id, $appointment->id);
    $invoice = makeClaimsPrintInvoice($patient, $appointment, $admission);
    $claim = makeClaimsPrintCase($invoice, $patient, $appointment, $admission);

    $this->actingAs($actor)
        ->get('/claims-insurance/'.$claim->id.'/print')
        ->assertForbidden();
});

it('renders a branded claims insurance print page with linked context', function (): void {
    $actor = makeClaimsPrintActor(['claims.insurance.read']);
    $followUpOwner = makeClaimsPrintActor();
    $patient = makeClaimsPrintPatient();
    $appointment = makeClaimsPrintAppointment($patient->id);
    $admission = makeClaimsPrintAdmission($patient->id, $appointment->id);
    $invoice = makeClaimsPrintInvoice($patient, $appointment, $admission);
    $claim = makeClaimsPrintCase($invoice, $patient, $appointment, $admission, $followUpOwner);

    app(SystemSettingsManager::class)->putMany([
        'branding.system_name' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Afyanova Claims Hub',
        ],
        'branding.mail_from_name' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Afyanova Revenue Desk',
        ],
        'branding.mail_reply_to_address' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'claims@afyanova.so',
        ],
        'branding.mail_footer_text' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Claims disputes should be escalated within the configured payer SLA.',
        ],
    ]);

    $this->actingAs($actor)
        ->get('/claims-insurance/'.$claim->id.'/print')
        ->assertInertia(fn (Assert $page) => $page
            ->component('claims-insurance/Print')
            ->where('claim.id', (string) $claim->id)
            ->where('claim.claimNumber', 'CLM-PRINT-0001')
            ->where('claim.status', 'partial')
            ->where('claim.reconciliationStatus', 'partial_settled')
            ->where('patient.patientNumber', 'PT-CLM-0001')
            ->where('patient.fullName', 'Neema John')
            ->where('appointment.appointmentNumber', 'APT-CLM-0001')
            ->where('admission.admissionNumber', 'ADM-CLM-0001')
            ->where('invoice.invoiceNumber', 'INV-CLM-0001')
            ->where('invoice.lineItemCount', 2)
            ->where('followUpOwner.name', $followUpOwner->name)
            ->where('documentBranding.systemName', 'Afyanova Claims Hub')
            ->where('documentBranding.issuedByName', 'Afyanova Revenue Desk')
            ->where('documentBranding.supportEmail', 'claims@afyanova.so')
            ->where('documentBranding.footerText', 'Claims disputes should be escalated within the configured payer SLA.')
            ->where('generatedAt', fn (string $value): bool => $value !== ''));
});

it('renders null optional context on the claims print page when no encounter or follow-up owner is linked', function (): void {
    $actor = makeClaimsPrintActor(['claims.insurance.read']);
    $patient = makeClaimsPrintPatient();
    $invoice = makeClaimsPrintInvoice($patient);
    $claim = makeClaimsPrintCase($invoice, $patient);

    $this->actingAs($actor)
        ->get('/claims-insurance/'.$claim->id.'/print')
        ->assertInertia(fn (Assert $page) => $page
            ->component('claims-insurance/Print')
            ->where('appointment', null)
            ->where('admission', null)
            ->where('followUpOwner', null)
            ->where('invoice.invoiceNumber', 'INV-CLM-0001'));
});

it('downloads the claims dossier as a branded pdf when authorized', function (): void {
    $actor = makeClaimsPrintActor(['claims.insurance.read']);
    $patient = makeClaimsPrintPatient();
    $appointment = makeClaimsPrintAppointment($patient->id);
    $admission = makeClaimsPrintAdmission($patient->id, $appointment->id);
    $invoice = makeClaimsPrintInvoice($patient, $appointment, $admission);
    $claim = makeClaimsPrintCase($invoice, $patient, $appointment, $admission);

    $response = $this->actingAs($actor)
        ->withHeader('User-Agent', 'Afyanova-Test-Agent/1.0')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
        ->get('/claims-insurance/'.$claim->id.'/pdf');

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Document-Format', 'pdf')
        ->assertHeader('X-Document-Schema-Version', 'document-pdf.v1')
        ->assertHeader('X-Document-Source', 'claims-insurance');

    expect((string) $response->headers->get('Content-Disposition'))
        ->toContain('.pdf');
    expect(substr((string) $response->getContent(), 0, 4))
        ->toBe('%PDF');

    $auditLog = ClaimsInsuranceCaseAuditLogModel::query()
        ->where('claims_insurance_case_id', $claim->id)
        ->latest('created_at')
        ->first();

    expect($auditLog)->not->toBeNull();
    expect($auditLog?->action)->toBe('claims-insurance-case.document.pdf.downloaded');
    expect($auditLog?->actor_id)->toBe($actor->id);
    expect($auditLog?->metadata['document_format'] ?? null)->toBe('pdf');
    expect($auditLog?->metadata['document_delivery'] ?? null)->toBe('download');
    expect($auditLog?->metadata['document_schema_version'] ?? null)->toBe('document-pdf.v1');
    expect($auditLog?->metadata['document_source'] ?? null)->toBe('claims-insurance');
    expect($auditLog?->metadata['document_source_id'] ?? null)->toBe($claim->id);
    expect($auditLog?->metadata['document_number'] ?? null)->toBe('CLM-PRINT-0001');
    expect($auditLog?->metadata['route_name'] ?? null)->toBe('claims-insurance.pdf.download');
    expect($auditLog?->metadata['request_path'] ?? null)->toBe('/claims-insurance/'.$claim->id.'/pdf');
    expect($auditLog?->metadata['request_ip'] ?? null)->toBe('203.0.113.11');
    expect($auditLog?->metadata['user_agent'] ?? null)->toBe('Afyanova-Test-Agent/1.0');
    expect($auditLog?->metadata['document_filename'] ?? '')->toContain('.pdf');
    expect($auditLog?->metadata['generated_at'] ?? null)->not->toBeNull();
});
