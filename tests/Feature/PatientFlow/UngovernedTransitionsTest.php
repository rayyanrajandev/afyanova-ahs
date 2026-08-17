<?php

use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\Permission;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\PatientFlow\Infrastructure\Models\PatientFlowEventModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Two transitions in already-shipped workspaces that recorded nothing.
|--------------------------------------------------------------------------
|
| Both are reachable from nursing and clinician, so they belong to this pass
| rather than a later workspace's (reports/laboratory-workspace-flow-plan.md,
| phase 5):
|
| - Admission wrote no flow event, so `admitted` was unreachable and an admitted
|   patient stopped moving on every board.
| - Return to reception wrote appointments.status with raw Eloquent, bypassing
|   the transition guard and the audit row, so `returned_to_reception` was
|   unreachable too.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
});

function ungovernedUser(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        Permission::query()->firstOrCreate(['name' => $permission]);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function ungovernedPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Baraka',
        'middle_name' => null,
        'last_name' => 'Mushi',
        'gender' => 'male',
        'date_of_birth' => '1988-07-02',
        'phone' => '+255700000011',
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

function ungovernedAppointment(string $patientId, array $overrides = []): AppointmentModel
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
        'status' => 'waiting_provider',
        'status_reason' => null,
    ], $overrides));
}

/**
 * The provider-workflow gate (ConsultationProviderAuthorization) needs more than
 * a permission: three read/create permissions *and* a clinical provider role, so
 * a clerk with the right permission bits still cannot manage a consultation.
 */
function ungovernedProviderUser(): User
{
    $user = ungovernedUser([
        'appointments.read',
        'appointments.manage-provider-session',
        'medical.records.read',
        'medical.records.create',
    ]);

    $role = \App\Modules\Platform\Infrastructure\Models\RoleModel::query()->create([
        'code' => \App\Support\Auth\RoleCodes::CLINICAL_PROVIDER_ROLES[0],
        'name' => 'Provider',
        'status' => 'active',
        'is_system' => true,
        'access_level' => 'request',
        'scope_type' => 'facility',
    ]);
    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh();
}

it('records returned_to_reception when nursing hands a patient back', function (): void {
    $user = ungovernedUser(['service.requests.create']);
    $patient = ungovernedPatient();
    $appointment = ungovernedAppointment($patient->id, [
        'nursing_contact_user_id' => $user->id,
        'nursing_contact_started_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/nursing/return-to-reception/'.$appointment->id, [
            'reason' => 'Insurance unverified',
        ])
        ->assertOk();

    $event = PatientFlowEventModel::query()
        ->where('appointment_id', $appointment->id)
        ->orderByDesc('occurred_at')
        ->orderByDesc('id')
        ->first();

    expect($event)->not->toBeNull();
    // The status really is waiting_triage — they are back in reception's queue —
    // but the step says what actually happened, which deriving from the status
    // alone could never express.
    expect($event->to_step)->toBe('returned_to_reception');
    expect($event->source)->toBe('nursing.returned_to_reception');
    expect($event->actor_user_id)->toBe($user->id);
});

it('still puts a returned patient back in the reception queue and ends the nursing contact', function (): void {
    $user = ungovernedUser(['service.requests.create']);
    $patient = ungovernedPatient();
    $appointment = ungovernedAppointment($patient->id, [
        'nursing_contact_user_id' => $user->id,
        'nursing_contact_started_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/nursing/return-to-reception/'.$appointment->id, [
            'reason' => 'Insurance unverified',
        ])
        ->assertOk();

    $appointment->refresh();

    expect($appointment->status)->toBe('waiting_triage');
    // Without clearing these the visit would keep reading "With Nurse" on every
    // queue after the nurse had let the patient go.
    expect($appointment->nursing_contact_user_id)->toBeNull();
    expect($appointment->nursing_contact_started_at)->toBeNull();
});

it('writes an audit row for a return to reception, which the raw Eloquent update never did', function (): void {
    $user = ungovernedUser(['service.requests.create']);
    $patient = ungovernedPatient();
    $appointment = ungovernedAppointment($patient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/nursing/return-to-reception/'.$appointment->id, [
            'reason' => 'Wrong clinic',
        ])
        ->assertOk();

    expect(
        \Illuminate\Support\Facades\DB::table('appointment_audit_logs')
            ->where('appointment_id', $appointment->id)
            ->count()
    )->toBeGreaterThan(0);
});

it('records admitted when a patient is admitted from the workspace', function (): void {
    $user = ungovernedUser(['service.requests.create']);
    $patient = ungovernedPatient();
    $appointment = ungovernedAppointment($patient->id, ['status' => 'in_consultation']);

    $encounter = \App\Modules\Encounter\Infrastructure\Models\EncounterModel::query()->create([
        'encounter_number' => 'ENC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'type' => 'outpatient',
        'status' => 'opened',
        'opened_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/nursing/admissions', [
            'patientId' => $patient->id,
            'appointmentId' => $appointment->id,
            'encounterId' => $encounter->id,
            'admittedAt' => now()->subMinute()->toDateTimeString(),
            'admissionReason' => 'Deteriorating vitals, oxygen support required',
        ])
        ->assertCreated();

    $event = PatientFlowEventModel::query()
        ->where('appointment_id', $appointment->id)
        ->orderByDesc('occurred_at')
        ->orderByDesc('id')
        ->first();

    expect($event)->not->toBeNull();
    // Without this the visit's last recorded step stayed whatever it was when
    // the decision to admit was made, and the patient stopped moving.
    expect($event->to_step)->toBe('admitted');
    expect($event->source)->toBe('nursing.patient_admitted');
    expect($event->actor_user_id)->toBe($user->id);
});

it('does not fail an admission when the flow log cannot be written', function (): void {
    $user = ungovernedUser(['service.requests.create']);
    $patient = ungovernedPatient();
    $appointment = ungovernedAppointment($patient->id, ['status' => 'in_consultation']);

    $encounter = \App\Modules\Encounter\Infrastructure\Models\EncounterModel::query()->create([
        'encounter_number' => 'ENC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'type' => 'outpatient',
        'status' => 'opened',
        'opened_at' => now()->subHour(),
    ]);

    \Illuminate\Support\Facades\Schema::drop('patient_flow_events');

    // A reporting gap must never become a patient who could not be admitted.
    $this->actingAs($user)
        ->postJson('/api/v1/nursing/admissions', [
            'patientId' => $patient->id,
            'appointmentId' => $appointment->id,
            'encounterId' => $encounter->id,
            'admittedAt' => now()->subMinute()->toDateTimeString(),
            'admissionReason' => 'Deteriorating vitals',
        ])
        ->assertCreated();
});

/*
|--------------------------------------------------------------------------
| Sending a patient out for diagnostics.
|--------------------------------------------------------------------------
|
| Ordering a test and sending the patient to the lab are two different acts.
| Releasing the consultation keeps the doctor's claim on the visit
| (consultation_started_at is preserved, so they come back as "waiting for
| doctor review") while freeing the room — the patient is at the lab, not with
| the doctor, and `with_clinician` asserts physical contact.
*/

it('records the diagnostic step, not a queue step, when a doctor sends a patient out', function (): void {
    $user = ungovernedProviderUser();
    $patient = ungovernedPatient();
    $appointment = ungovernedAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(10),
        'consultation_owner_user_id' => $user->id,
        'consultation_owner_assigned_at' => now()->subMinutes(10),
    ]);
    \App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'ordered_at' => now(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'status' => 'ordered',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/clinician/visits/'.$appointment->id.'/provider-workflow', [
            'status' => 'waiting_provider',
        ])
        ->assertOk();

    $event = PatientFlowEventModel::query()
        ->where('appointment_id', $appointment->id)
        ->orderByDesc('occurred_at')
        ->orderByDesc('id')
        ->first();

    expect($event)->not->toBeNull();
    // Derived from the status alone this would read waiting_clinician_review the
    // instant the patient walked out, before the lab had even seen them.
    expect($event->to_step)->toBe('waiting_lab');
    expect($event->source)->toBe('clinician.sent_for_diagnostics');
});

it('keeps the doctor on the visit when the patient is sent out, so they return for review', function (): void {
    $user = ungovernedProviderUser();
    $patient = ungovernedPatient();
    $appointment = ungovernedAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(10),
        'consultation_owner_user_id' => $user->id,
        'consultation_owner_assigned_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/clinician/visits/'.$appointment->id.'/provider-workflow', [
            'status' => 'waiting_provider',
        ])
        ->assertOk();

    $appointment->refresh();

    // The room is released…
    expect($appointment->status)->toBe('waiting_provider');
    expect($appointment->consultation_owner_user_id)->toBeNull();
    // …but the visit remembers it has already been seen, which is what makes the
    // patient come back as waiting_clinician_review rather than re-queueing as
    // though they had never met a doctor.
    expect($appointment->consultation_started_at)->not->toBeNull();
});

it('falls back to the queue step when a doctor releases a patient with nothing outstanding', function (): void {
    $user = ungovernedProviderUser();
    $patient = ungovernedPatient();
    $appointment = ungovernedAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(10),
        'consultation_owner_user_id' => $user->id,
        'consultation_owner_assigned_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/clinician/visits/'.$appointment->id.'/provider-workflow', [
            'status' => 'waiting_provider',
        ])
        ->assertOk();

    $event = PatientFlowEventModel::query()
        ->where('appointment_id', $appointment->id)
        ->orderByDesc('occurred_at')
        ->orderByDesc('id')
        ->first();

    // No open order means the doctor is not sending them anywhere in
    // particular, so the derived step stands rather than inventing a department.
    expect($event->to_step)->toBe('waiting_clinician_review');
});
