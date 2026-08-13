<?php

use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientAllergyModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\PatientFlow\Application\UseCases\GetActiveVisitJourneyUseCase;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\ServiceRequest\Infrastructure\Models\ServiceRequestModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePatientFlowPatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Furaha',
        'middle_name' => null,
        'last_name' => 'Ngowi',
        'gender' => 'female',
        'date_of_birth' => '1990-01-01',
        'phone' => '+255700000002',
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
    ], $overrides));
}

function makePatientFlowAppointment(string $patientId, array $overrides = []): AppointmentModel
{
    return AppointmentModel::query()->create(array_merge([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Visit',
        'notes' => null,
        'status' => 'waiting_triage',
        'status_reason' => null,
    ], $overrides));
}

function makePatientFlowLabOrder(string $patientId, string $appointmentId, string $status): LaboratoryOrderModel
{
    return LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'ordered_at' => now(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'status' => $status,
    ]);
}

function makePatientFlowRadiologyOrder(string $patientId, string $appointmentId, string $status): RadiologyOrderModel
{
    return RadiologyOrderModel::query()->create([
        'order_number' => 'RAD'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'ordered_at' => now(),
        'modality' => 'xray',
        'study_description' => 'Chest X-Ray (PA)',
        'status' => $status,
    ]);
}

function makePatientFlowPharmacyOrder(string $patientId, string $appointmentId, string $status): PharmacyOrderModel
{
    return PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'ordered_at' => now(),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => 'Take 1 tablet every 8 hours',
        'quantity_prescribed' => 12,
        'quantity_dispensed' => 0,
        'status' => $status,
    ]);
}

function makePatientFlowServiceRequest(
    string $patientId,
    string $status,
    ?string $appointmentId = null,
    ?string $linkedOrderId = null,
    string $serviceType = 'laboratory',
    ?\Illuminate\Support\Carbon $requestedAt = null,
    ?\Illuminate\Support\Carbon $acknowledgedAt = null,
): ServiceRequestModel {
    return ServiceRequestModel::query()->create([
        'request_number' => 'SR'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'service_type' => $serviceType,
        'priority' => 'routine',
        'status' => $status,
        'requested_at' => $requestedAt ?? now(),
        'acknowledged_at' => $acknowledgedAt,
        'linked_order_id' => $linkedOrderId,
    ]);
}

function makePatientFlowAllergy(string $patientId, array $overrides = []): PatientAllergyModel
{
    return PatientAllergyModel::query()->create(array_merge([
        'patient_id' => $patientId,
        'substance_name' => 'Penicillin',
        'severity' => 'severe',
        'status' => 'active',
    ], $overrides));
}

function makePatientFlowInvoice(string $patientId, array $overrides = []): BillingInvoiceModel
{
    return BillingInvoiceModel::query()->create(array_merge([
        'invoice_number' => 'INV'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'invoice_date' => now(),
        'subtotal_amount' => 100,
        'total_amount' => 100,
        'balance_amount' => 100,
        'status' => 'issued',
    ], $overrides));
}

it('maps waiting_triage appointments to the waiting_triage step', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, ['status' => 'waiting_triage']);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries)->toHaveCount(1);
    expect($entries[0]['appointmentId'])->toBe($appointment->id);
    expect($entries[0]['step'])->toBe('waiting_triage');
    expect($entries[0]['patientName'])->toBe('Furaha Ngowi');
    expect($entries[0]['patientNumber'])->toBe($patient->patient_number);
});

it('maps a claimed waiting_triage appointment to in_triage', function (): void {
    $patient = makePatientFlowPatient();
    $nurse = User::factory()->create();
    makePatientFlowAppointment($patient->id, [
        'status' => 'waiting_triage',
        'triage_owner_user_id' => $nurse->id,
        'triage_owner_assigned_at' => now(),
    ]);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('in_triage');
});

it('maps a first-time waiting_provider appointment to waiting_clinician', function (): void {
    $patient = makePatientFlowPatient();
    makePatientFlowAppointment($patient->id, [
        'status' => 'waiting_provider',
        'consultation_started_at' => null,
    ]);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_clinician');
});

it('maps a returning waiting_provider appointment to waiting_clinician_review', function (): void {
    $patient = makePatientFlowPatient();
    makePatientFlowAppointment($patient->id, [
        'status' => 'waiting_provider',
        'consultation_started_at' => now()->subMinutes(20),
    ]);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_clinician_review');
});

it('maps in_consultation with no open orders to with_clinician', function (): void {
    $patient = makePatientFlowPatient();
    makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('with_clinician');
});

it('maps in_consultation with an unstarted lab order to waiting_lab', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    makePatientFlowLabOrder($patient->id, $appointment->id, 'ordered');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_lab');
});

it('maps in_consultation with an in-progress lab order to in_lab', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    makePatientFlowLabOrder($patient->id, $appointment->id, 'collected');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('in_lab');
});

it('prefers waiting_lab over in_lab when both an unstarted and an in-progress order exist', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    makePatientFlowLabOrder($patient->id, $appointment->id, 'ordered');
    makePatientFlowLabOrder($patient->id, $appointment->id, 'in_progress');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_lab');
});

it('maps in_consultation with a scheduled radiology order to waiting_imaging', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    makePatientFlowRadiologyOrder($patient->id, $appointment->id, 'scheduled');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_imaging');
});

it('maps in_consultation with an in-progress radiology order to in_imaging', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    makePatientFlowRadiologyOrder($patient->id, $appointment->id, 'in_progress');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('in_imaging');
});

it('maps in_consultation with both an unstarted lab order and an unstarted radiology order to waiting_lab_and_imaging', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    makePatientFlowLabOrder($patient->id, $appointment->id, 'ordered');
    makePatientFlowRadiologyOrder($patient->id, $appointment->id, 'scheduled');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_lab_and_imaging');
});

it('maps in_consultation with only an open pharmacy order to waiting_pharmacy', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    makePatientFlowPharmacyOrder($patient->id, $appointment->id, 'pending');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_pharmacy');
});

it('ignores completed lab orders when deriving the step', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    makePatientFlowLabOrder($patient->id, $appointment->id, 'completed');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('with_clinician');
});

it('sources stepEnteredAt from checked_in_at for waiting_triage', function (): void {
    $patient = makePatientFlowPatient();
    $checkedInAt = now()->subMinutes(15)->startOfSecond();
    makePatientFlowAppointment($patient->id, [
        'status' => 'waiting_triage',
        'checked_in_at' => $checkedInAt,
    ]);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['stepEnteredAt'])->toBe($checkedInAt->toISOString());
});

it('sources stepEnteredAt from triage_owner_assigned_at for in_triage', function (): void {
    $patient = makePatientFlowPatient();
    $nurse = User::factory()->create();
    $claimedAt = now()->subMinutes(5)->startOfSecond();
    makePatientFlowAppointment($patient->id, [
        'status' => 'waiting_triage',
        'triage_owner_user_id' => $nurse->id,
        'triage_owner_assigned_at' => $claimedAt,
    ]);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['stepEnteredAt'])->toBe($claimedAt->toISOString());
});

it('leaves stepEnteredAt null for waiting_clinician, honestly, since no column marks it', function (): void {
    $patient = makePatientFlowPatient();
    makePatientFlowAppointment($patient->id, [
        'status' => 'waiting_provider',
        'consultation_started_at' => null,
    ]);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_clinician');
    expect($entries[0]['stepEnteredAt'])->toBeNull();
});

it('leaves stepEnteredAt null for waiting_clinician_review, honestly, since no column marks it', function (): void {
    $patient = makePatientFlowPatient();
    makePatientFlowAppointment($patient->id, [
        'status' => 'waiting_provider',
        'consultation_started_at' => now()->subMinutes(20),
    ]);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_clinician_review');
    expect($entries[0]['stepEnteredAt'])->toBeNull();
});

it('sources stepEnteredAt from consultation_started_at for with_clinician', function (): void {
    $patient = makePatientFlowPatient();
    $startedAt = now()->subMinutes(10)->startOfSecond();
    makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => $startedAt,
    ]);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('with_clinician');
    expect($entries[0]['stepEnteredAt'])->toBe($startedAt->toISOString());
});

it('sources stepEnteredAt from the earliest open lab order for waiting_lab', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    $earlierOrder = makePatientFlowLabOrder($patient->id, $appointment->id, 'ordered');
    $earlierOrder->forceFill(['ordered_at' => now()->subMinutes(40)])->save();
    $laterOrder = makePatientFlowLabOrder($patient->id, $appointment->id, 'ordered');
    $laterOrder->forceFill(['ordered_at' => now()->subMinutes(5)])->save();

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_lab');
    expect($entries[0]['stepEnteredAt'])->toBe($earlierOrder->fresh()->ordered_at->toISOString());
});

it('sources stepEnteredAt from the earliest open pharmacy order for waiting_pharmacy', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    $order = makePatientFlowPharmacyOrder($patient->id, $appointment->id, 'pending');
    $orderedAt = now()->subMinutes(25)->startOfSecond();
    $order->forceFill(['ordered_at' => $orderedAt])->save();

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_pharmacy');
    expect($entries[0]['stepEnteredAt'])->toBe($orderedAt->toISOString());
});

it('maps a pending, unlinked service request to waiting_direct_service with no appointment', function (): void {
    $patient = makePatientFlowPatient();
    $serviceRequest = makePatientFlowServiceRequest($patient->id, 'pending');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries)->toHaveCount(1);
    expect($entries[0]['appointmentId'])->toBeNull();
    expect($entries[0]['serviceRequestId'])->toBe($serviceRequest->id);
    expect($entries[0]['step'])->toBe('waiting_direct_service');
    expect($entries[0]['department'])->toBe('Laboratory');
    expect($entries[0]['patientName'])->toBe('Furaha Ngowi');
});

it('maps an in-progress service request to in_direct_service', function (): void {
    $patient = makePatientFlowPatient();
    makePatientFlowServiceRequest($patient->id, 'in_progress', serviceType: 'pharmacy');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('in_direct_service');
    expect($entries[0]['department'])->toBe('Pharmacy');
});

it('sources stepEnteredAt from requested_at for waiting_direct_service', function (): void {
    $patient = makePatientFlowPatient();
    $requestedAt = now()->subMinutes(12)->startOfSecond();
    makePatientFlowServiceRequest($patient->id, 'pending', requestedAt: $requestedAt);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_direct_service');
    expect($entries[0]['stepEnteredAt'])->toBe($requestedAt->toISOString());
});

it('sources stepEnteredAt from acknowledged_at (not requested_at) for in_direct_service', function (): void {
    $patient = makePatientFlowPatient();
    $requestedAt = now()->subMinutes(30)->startOfSecond();
    $acknowledgedAt = now()->subMinutes(8)->startOfSecond();
    makePatientFlowServiceRequest(
        $patient->id,
        'in_progress',
        requestedAt: $requestedAt,
        acknowledgedAt: $acknowledgedAt,
    );

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('in_direct_service');
    expect($entries[0]['stepEnteredAt'])->toBe($acknowledgedAt->toISOString());
});

it('excludes a service request already linked to a real order', function (): void {
    $patient = makePatientFlowPatient();
    makePatientFlowServiceRequest($patient->id, 'pending', linkedOrderId: (string) Str::uuid());

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries)->toBe([]);
});

it('excludes completed and cancelled service requests', function (): void {
    $patient = makePatientFlowPatient();
    makePatientFlowServiceRequest($patient->id, 'completed');
    makePatientFlowServiceRequest($patient->id, 'cancelled');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries)->toBe([]);
});

it('shows a service request tied to an appointment alongside the appointment entry', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, ['status' => 'in_consultation']);
    makePatientFlowServiceRequest($patient->id, 'pending', appointmentId: $appointment->id);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries)->toHaveCount(2);
    $steps = array_column($entries, 'step');
    expect($steps)->toContain('with_clinician');
    expect($steps)->toContain('waiting_direct_service');
});

it('carries the open lab/radiology/pharmacy orders as openOrders regardless of which one determines the step', function (): void {
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    makePatientFlowLabOrder($patient->id, $appointment->id, 'ordered');
    makePatientFlowRadiologyOrder($patient->id, $appointment->id, 'scheduled');
    makePatientFlowPharmacyOrder($patient->id, $appointment->id, 'pending');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries[0]['step'])->toBe('waiting_lab_and_imaging');
    expect($entries[0]['openOrders'])->toHaveCount(3);
    expect(array_column($entries[0]['openOrders'], 'type'))->toEqualCanonicalizing(['lab', 'imaging', 'pharmacy']);
    expect(array_column($entries[0]['openOrders'], 'label'))->toEqualCanonicalizing([
        'Complete Blood Count', 'Chest X-Ray (PA)', 'Paracetamol 500mg',
    ]);
});

it('carries the appointment triage_category through as priority, null for service requests', function (): void {
    $patient = makePatientFlowPatient();
    makePatientFlowAppointment($patient->id, ['status' => 'waiting_triage', 'triage_category' => 'P2']);
    makePatientFlowServiceRequest($patient->id, 'pending');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    $byStep = collect($entries)->keyBy('step');
    expect($byStep['waiting_triage']['priority'])->toBe('P2');
    expect($byStep['waiting_direct_service']['priority'])->toBeNull();
});

it('surfaces active allergies for a patient, empty array when none', function (): void {
    $withAllergy = makePatientFlowPatient();
    makePatientFlowAppointment($withAllergy->id, ['status' => 'waiting_triage']);
    makePatientFlowAllergy($withAllergy->id, ['substance_name' => 'Penicillin', 'severity' => 'severe']);
    makePatientFlowAllergy($withAllergy->id, ['substance_name' => 'Old resolved allergy', 'status' => 'inactive']);

    $withoutAllergy = makePatientFlowPatient(['phone' => '+255700000097']);
    makePatientFlowAppointment($withoutAllergy->id, ['status' => 'waiting_triage']);

    $entries = collect(app(GetActiveVisitJourneyUseCase::class)->execute())->keyBy('patientId');

    expect($entries[$withAllergy->id]['allergies'])->toBe([
        ['substanceName' => 'Penicillin', 'severity' => 'severe'],
    ]);
    expect($entries[$withoutAllergy->id]['allergies'])->toBe([]);
});

it('flags billingStatus pending only for issued/partially_paid invoices, not draft/paid/cancelled/voided', function (): void {
    $pending = makePatientFlowPatient();
    makePatientFlowAppointment($pending->id, ['status' => 'waiting_triage']);
    makePatientFlowInvoice($pending->id, ['status' => 'partially_paid']);

    $settled = makePatientFlowPatient(['phone' => '+255700000096']);
    makePatientFlowAppointment($settled->id, ['status' => 'waiting_triage']);
    makePatientFlowInvoice($settled->id, ['status' => 'paid']);

    $entries = collect(app(GetActiveVisitJourneyUseCase::class)->execute())->keyBy('patientId');

    expect($entries[$pending->id]['billingStatus'])->toBe('pending');
    expect($entries[$settled->id]['billingStatus'])->toBeNull();
});

it('filters the board by department, pushed into the query', function (): void {
    $patient = makePatientFlowPatient();
    makePatientFlowAppointment($patient->id, ['status' => 'waiting_triage', 'department' => 'Outpatient']);
    makePatientFlowAppointment($patient->id, ['status' => 'waiting_provider', 'department' => 'Emergency']);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute(department: 'Emergency');

    expect($entries)->toHaveCount(1);
    expect($entries[0]['department'])->toBe('Emergency');
});

it('filters the board by clinicianUserId, excluding service requests entirely since they have none', function (): void {
    $clinician = User::factory()->create();
    $patient = makePatientFlowPatient();
    makePatientFlowAppointment($patient->id, ['status' => 'waiting_provider', 'clinician_user_id' => $clinician->id]);
    makePatientFlowAppointment($patient->id, ['status' => 'waiting_provider', 'clinician_user_id' => null]);
    makePatientFlowServiceRequest($patient->id, 'pending');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute(clinicianUserId: $clinician->id);

    expect($entries)->toHaveCount(1);
    expect($entries[0]['clinicianUserId'])->toBe($clinician->id);
});

it('shows the current consultation owner instead of the originally scheduled clinician after a takeover', function (): void {
    $originalClinician = User::factory()->create();
    $replacementClinician = User::factory()->create();
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'clinician_user_id' => $originalClinician->id,
        'consultation_owner_user_id' => $replacementClinician->id,
        'consultation_takeover_count' => 1,
    ]);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute(patientId: $patient->id);

    $entry = collect($entries)->firstWhere('appointmentId', $appointment->id);
    expect($entry['clinicianUserId'])->toBe($replacementClinician->id);
    expect($entry['consultationTakeoverCount'])->toBe(1);
});

it('falls back to the originally scheduled clinician when no consultation owner has been assigned yet', function (): void {
    $clinician = User::factory()->create();
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'waiting_provider',
        'clinician_user_id' => $clinician->id,
        'consultation_owner_user_id' => null,
        'consultation_takeover_count' => 0,
    ]);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute(patientId: $patient->id);

    $entry = collect($entries)->firstWhere('appointmentId', $appointment->id);
    expect($entry['clinicianUserId'])->toBe($clinician->id);
    expect($entry['consultationTakeoverCount'])->toBe(0);
});

it('filters the board by a patient name/number search term, in memory', function (): void {
    $match = makePatientFlowPatient(['first_name' => 'Amina', 'last_name' => 'Juma']);
    $other = makePatientFlowPatient(['first_name' => 'Baraka', 'last_name' => 'Mwakalinga', 'phone' => '+255700000095']);
    makePatientFlowAppointment($match->id, ['status' => 'waiting_triage']);
    makePatientFlowAppointment($other->id, ['status' => 'waiting_triage']);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute(q: 'amina');

    expect($entries)->toHaveCount(1);
    expect($entries[0]['patientId'])->toBe($match->id);
});

it('runs a bounded, N-independent number of queries regardless of active-visit volume', function (): void {
    // Direct answer to the plan's own §2.2/§6 requirement: measure this
    // before trusting it, per the encounter-state-machine-design/02 lesson
    // where an unmeasured cross-module aggregation turned out to cost ~91
    // queries under real load. This asserts the batched-query design (one
    // query per data source, never per visit) actually holds, not just that
    // it looks right on paper.
    $patient = makePatientFlowPatient();

    for ($i = 0; $i < 150; $i++) {
        $appointment = makePatientFlowAppointment($patient->id, [
            'status' => 'in_consultation',
            'consultation_started_at' => now(),
        ]);
        makePatientFlowLabOrder($patient->id, $appointment->id, 'ordered');
        makePatientFlowPharmacyOrder($patient->id, $appointment->id, 'pending');
    }

    DB::enableQueryLog();
    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($entries)->toHaveCount(150);
    // Ceiling bumped from 6 to 8 for the Phase 1 card-enrichment pass: one
    // more batched query each for allergies and pending-invoice lookups,
    // still one query per data source regardless of visit volume.
    expect($queryCount)->toBeLessThanOrEqual(8);
});

it('excludes completed, cancelled, and no_show appointments entirely', function (): void {
    $patient = makePatientFlowPatient();
    makePatientFlowAppointment($patient->id, ['status' => 'completed']);
    makePatientFlowAppointment($patient->id, ['status' => 'cancelled']);
    makePatientFlowAppointment($patient->id, ['status' => 'no_show']);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute();

    expect($entries)->toBe([]);
});

it('scopes the board to one patient when a patientId is given, pushed into the query rather than filtered after', function (): void {
    $target = makePatientFlowPatient();
    $other = makePatientFlowPatient(['phone' => '+255700000099']);
    makePatientFlowAppointment($target->id, ['status' => 'waiting_triage']);
    makePatientFlowAppointment($other->id, ['status' => 'waiting_provider']);

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute($target->id);

    expect($entries)->toHaveCount(1);
    expect($entries[0]['patientId'])->toBe($target->id);
});

it('scopes direct-service walk-ins to one patient when a patientId is given', function (): void {
    $target = makePatientFlowPatient();
    $other = makePatientFlowPatient(['phone' => '+255700000098']);
    makePatientFlowServiceRequest($target->id, 'pending');
    makePatientFlowServiceRequest($other->id, 'pending');

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute($target->id);

    expect($entries)->toHaveCount(1);
    expect($entries[0]['patientId'])->toBe($target->id);
    expect($entries[0]['step'])->toBe('waiting_direct_service');
});

it('returns an empty array when the scoped patientId has no active visit', function (): void {
    $patient = makePatientFlowPatient();

    $entries = app(GetActiveVisitJourneyUseCase::class)->execute($patient->id);

    expect($entries)->toBe([]);
});
