<?php

use App\Models\User;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\InpatientWard\Infrastructure\Models\InpatientWardCarePlanModel;
use App\Modules\InpatientWard\Infrastructure\Models\InpatientWardDischargeChecklistAuditLogModel;
use App\Modules\InpatientWard\Infrastructure\Models\InpatientWardDischargeChecklistModel;
use App\Modules\InpatientWard\Infrastructure\Models\InpatientWardRoundNoteModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Support\Settings\SystemSettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function makeInpatientDischargePrintActor(array $permissions = []): User
{
    return makeUserWithRole($permissions);
}

function makeInpatientDischargePrintPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-DCH-0001',
        'first_name' => 'Mariam',
        'middle_name' => null,
        'last_name' => 'Msuya',
        'gender' => 'female',
        'date_of_birth' => '1990-07-18',
        'phone' => '+255700998877',
        'email' => 'mariam@example.test',
        'country_code' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Kinondoni',
        'address_line' => 'Sinza',
        'status' => 'active',
    ]);
}

function makeInpatientDischargePrintAdmission(string $patientId): AdmissionModel
{
    return AdmissionModel::query()->create([
        'admission_number' => 'ADM-DCH-0001',
        'patient_id' => $patientId,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward D',
        'bed' => 'D-11',
        'admitted_at' => '2026-04-06 15:30:00',
        'discharged_at' => '2026-04-09 14:45:00',
        'admission_reason' => 'Respiratory stabilization and observation',
        'notes' => 'Family requested discharge counseling before leaving the ward.',
        'status' => 'discharged',
        'status_reason' => 'Medically fit for home discharge.',
        'discharge_destination' => 'Home',
        'follow_up_plan' => 'Return to respiratory clinic in 7 days and continue antibiotics for 5 days.',
    ]);
}

function makeInpatientDischargeChecklist(AdmissionModel $admission, PatientModel $patient, User $reviewer): InpatientWardDischargeChecklistModel
{
    return InpatientWardDischargeChecklistModel::query()->create([
        'admission_id' => $admission->id,
        'patient_id' => $patient->id,
        'status' => 'completed',
        'status_reason' => 'All discharge gates cleared and family notified.',
        'clinical_summary_completed' => true,
        'medication_reconciliation_completed' => true,
        'follow_up_plan_completed' => true,
        'patient_education_completed' => true,
        'transport_arranged' => true,
        'billing_cleared' => true,
        'documentation_completed' => true,
        'is_ready_for_discharge' => true,
        'last_reviewed_by_user_id' => $reviewer->id,
        'reviewed_at' => '2026-04-09 13:50:00',
        'notes' => 'Patient received medication counseling and discharge handout.',
        'metadata' => null,
    ]);
}

function seedInpatientDischargePrintContext(AdmissionModel $admission, PatientModel $patient, User $roundAuthor, User $acknowledger, User $carePlanAuthor): void
{
    InpatientWardRoundNoteModel::query()->create([
        'admission_id' => $admission->id,
        'patient_id' => $patient->id,
        'author_user_id' => $roundAuthor->id,
        'rounded_at' => '2026-04-09 08:15:00',
        'shift_label' => 'day',
        'round_note' => 'Patient stable on room air and mobilizing independently.',
        'care_plan' => 'Complete discharge paperwork and reinforce inhaler technique.',
        'handoff_notes' => 'Cashier to confirm final settlement and nursing to issue take-home medications.',
        'acknowledged_by_user_id' => $acknowledger->id,
        'acknowledged_at' => '2026-04-09 09:10:00',
        'metadata' => null,
    ]);

    InpatientWardCarePlanModel::query()->create([
        'care_plan_number' => 'CP-DCH-0001',
        'admission_id' => $admission->id,
        'patient_id' => $patient->id,
        'title' => 'Discharge coordination plan',
        'plan_text' => 'Finalize counseling, pharmacy release, and clinic follow-up booking.',
        'goals' => ['Safe home discharge', 'Medication adherence'],
        'interventions' => ['Counsel patient', 'Issue take-home therapy'],
        'target_discharge_at' => '2026-04-09 15:00:00',
        'review_due_at' => '2026-04-09 12:00:00',
        'status' => 'completed',
        'status_reason' => 'Discharge coordination tasks finished.',
        'author_user_id' => $carePlanAuthor->id,
        'last_updated_by_user_id' => $carePlanAuthor->id,
        'metadata' => null,
    ]);

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB-DCH-0001',
        'patient_id' => $patient->id,
        'admission_id' => $admission->id,
        'ordered_at' => '2026-04-09 07:30:00',
        'test_code' => 'CBC',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'status' => 'ordered',
    ]);

    PharmacyOrderModel::query()->create([
        'order_number' => 'RX-DCH-0001',
        'patient_id' => $patient->id,
        'admission_id' => $admission->id,
        'ordered_at' => '2026-04-09 08:00:00',
        'medication_code' => 'AZM500',
        'medication_name' => 'Azithromycin',
        'dosage_instruction' => '500 mg once daily',
        'quantity_prescribed' => 3,
        'quantity_dispensed' => 0,
        'status' => 'pending',
    ]);

    RadiologyOrderModel::query()->create([
        'order_number' => 'RAD-DCH-0001',
        'patient_id' => $patient->id,
        'admission_id' => $admission->id,
        'ordered_at' => '2026-04-09 08:20:00',
        'modality' => 'xray',
        'study_description' => 'Chest X-Ray',
        'scheduled_for' => '2026-04-09 11:30:00',
        'status' => 'scheduled',
    ]);

    BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV-DCH-0001',
        'patient_id' => $patient->id,
        'admission_id' => $admission->id,
        'invoice_date' => '2026-04-09 09:00:00',
        'currency_code' => 'TZS',
        'subtotal_amount' => 85000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 85000,
        'paid_amount' => 0,
        'balance_amount' => 85000,
        'payment_due_at' => '2026-04-10 23:59:59',
        'status' => 'issued',
    ]);
}

it('forbids the inpatient discharge print page without inpatient ward read permission', function (): void {
    $actor = makeInpatientDischargePrintActor();
    $reviewer = makeInpatientDischargePrintActor();
    $patient = makeInpatientDischargePrintPatient();
    $admission = makeInpatientDischargePrintAdmission($patient->id);
    $checklist = makeInpatientDischargeChecklist($admission, $patient, $reviewer);

    $this->actingAs($actor)
        ->get('/inpatient-ward/discharge-checklists/'.$checklist->id.'/print')
        ->assertForbidden();
});

it('renders a branded inpatient discharge print page with ward context and follow-up load', function (): void {
    $actor = makeInpatientDischargePrintActor(['inpatient.ward.read']);
    $reviewer = makeInpatientDischargePrintActor();
    $roundAuthor = makeInpatientDischargePrintActor();
    $acknowledger = makeInpatientDischargePrintActor();
    $carePlanAuthor = makeInpatientDischargePrintActor();
    $patient = makeInpatientDischargePrintPatient();
    $admission = makeInpatientDischargePrintAdmission($patient->id);
    $checklist = makeInpatientDischargeChecklist($admission, $patient, $reviewer);

    seedInpatientDischargePrintContext($admission, $patient, $roundAuthor, $acknowledger, $carePlanAuthor);

    app(SystemSettingsManager::class)->putMany([
        'branding.system_name' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Afyanova Ward Desk',
        ],
        'branding.mail_from_name' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Afyanova Discharge Team',
        ],
        'branding.mail_reply_to_address' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'discharge@afyanova.so',
        ],
        'branding.mail_footer_text' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Discharge printouts should be handled according to facility privacy policy.',
        ],
    ]);

    $this->actingAs($actor)
        ->get('/inpatient-ward/discharge-checklists/'.$checklist->id.'/print')
        ->assertInertia(fn (Assert $page) => $page
            ->component('inpatient-ward/Print')
            ->where('checklist.id', (string) $checklist->id)
            ->where('checklist.status', 'completed')
            ->where('checklist.isReadyForDischarge', true)
            ->where('patient.patientNumber', 'PT-DCH-0001')
            ->where('patient.fullName', 'Mariam Msuya')
            ->where('admission.admissionNumber', 'ADM-DCH-0001')
            ->where('admission.dischargeDestination', 'Home')
            ->where('admission.followUpPlan', 'Return to respiratory clinic in 7 days and continue antibiotics for 5 days.')
            ->where('reviewer.name', $reviewer->name)
            ->where('roundNotes.0.author.name', $roundAuthor->name)
            ->where('roundNotes.0.acknowledgedBy.name', $acknowledger->name)
            ->where('carePlans.0.author.name', $carePlanAuthor->name)
            ->where('followUpRail.modules.laboratory.followUpCount', 1)
            ->where('followUpRail.modules.pharmacy.followUpCount', 1)
            ->where('followUpRail.modules.radiology.followUpCount', 1)
            ->where('followUpRail.modules.billing.followUpCount', 1)
            ->where('followUpRail.modules.laboratory.items.0.number', 'LAB-DCH-0001')
            ->where('followUpRail.modules.pharmacy.items.0.number', 'RX-DCH-0001')
            ->where('followUpRail.modules.radiology.items.0.number', 'RAD-DCH-0001')
            ->where('followUpRail.modules.billing.items.0.number', 'INV-DCH-0001')
            ->where('documentBranding.systemName', 'Afyanova Ward Desk')
            ->where('documentBranding.issuedByName', 'Afyanova Discharge Team')
            ->where('documentBranding.supportEmail', 'discharge@afyanova.so')
            ->where('documentBranding.footerText', 'Discharge printouts should be handled according to facility privacy policy.')
            ->where('generatedAt', fn (string $value): bool => $value !== ''));
});

it('renders empty discharge context blocks when no round notes care plans or follow-up items are linked', function (): void {
    $actor = makeInpatientDischargePrintActor(['inpatient.ward.read']);
    $reviewer = makeInpatientDischargePrintActor();
    $patient = makeInpatientDischargePrintPatient();
    $admission = makeInpatientDischargePrintAdmission($patient->id);
    $checklist = makeInpatientDischargeChecklist($admission, $patient, $reviewer);

    $this->actingAs($actor)
        ->get('/inpatient-ward/discharge-checklists/'.$checklist->id.'/print')
        ->assertInertia(fn (Assert $page) => $page
            ->component('inpatient-ward/Print')
            ->where('roundNotes', [])
            ->where('carePlans', [])
            ->where('followUpRail.modules.laboratory.items', [])
            ->where('followUpRail.modules.pharmacy.items', [])
            ->where('followUpRail.modules.radiology.items', [])
            ->where('followUpRail.modules.billing.items', []));
});

it('downloads the inpatient discharge summary as a branded pdf when authorized', function (): void {
    $actor = makeInpatientDischargePrintActor(['inpatient.ward.read']);
    $reviewer = makeInpatientDischargePrintActor();
    $patient = makeInpatientDischargePrintPatient();
    $admission = makeInpatientDischargePrintAdmission($patient->id);
    $checklist = makeInpatientDischargeChecklist($admission, $patient, $reviewer);

    $response = $this->actingAs($actor)
        ->withHeader('User-Agent', 'Afyanova-Test-Agent/1.0')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.13'])
        ->get('/inpatient-ward/discharge-checklists/'.$checklist->id.'/pdf');

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Document-Format', 'pdf')
        ->assertHeader('X-Document-Schema-Version', 'document-pdf.v1')
        ->assertHeader('X-Document-Source', 'inpatient-discharge');

    expect((string) $response->headers->get('Content-Disposition'))
        ->toContain('.pdf');
    expect(substr((string) $response->getContent(), 0, 4))
        ->toBe('%PDF');

    $auditLog = InpatientWardDischargeChecklistAuditLogModel::query()
        ->where('inpatient_ward_discharge_checklist_id', $checklist->id)
        ->latest('created_at')
        ->first();

    expect($auditLog)->not->toBeNull();
    expect($auditLog?->action)->toBe('inpatient-ward-discharge-checklist.document.pdf.downloaded');
    expect($auditLog?->actor_id)->toBe($actor->id);
    expect($auditLog?->metadata['document_format'] ?? null)->toBe('pdf');
    expect($auditLog?->metadata['document_delivery'] ?? null)->toBe('download');
    expect($auditLog?->metadata['document_schema_version'] ?? null)->toBe('document-pdf.v1');
    expect($auditLog?->metadata['document_source'] ?? null)->toBe('inpatient-discharge');
    expect($auditLog?->metadata['document_source_id'] ?? null)->toBe($checklist->id);
    expect($auditLog?->metadata['document_number'] ?? null)->toBe('ADM-DCH-0001');
    expect($auditLog?->metadata['route_name'] ?? null)->toBe('inpatient-ward-discharge-checklists.pdf.download');
    expect($auditLog?->metadata['request_path'] ?? null)->toBe('/inpatient-ward/discharge-checklists/'.$checklist->id.'/pdf');
    expect($auditLog?->metadata['request_ip'] ?? null)->toBe('203.0.113.13');
    expect($auditLog?->metadata['user_agent'] ?? null)->toBe('Afyanova-Test-Agent/1.0');
    expect($auditLog?->metadata['document_filename'] ?? '')->toContain('.pdf');
    expect($auditLog?->metadata['generated_at'] ?? null)->not->toBeNull();
});
