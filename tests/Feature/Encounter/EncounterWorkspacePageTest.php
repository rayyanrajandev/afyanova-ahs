<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);
});

function makeEncounterPageUser(array $permissions = []): User
{
    return makeUserWithRole($permissions);
}

function makeEncounterPagePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-ENC-PAGE-0001',
        'first_name' => 'Neema',
        'middle_name' => null,
        'last_name' => 'Mushi',
        'gender' => 'female',
        'date_of_birth' => '1994-03-12',
        'phone' => '+255700222333',
        'email' => 'neema@example.test',
        'country_code' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Kinondoni',
        'address_line' => 'Mikocheni',
        'status' => 'active',
    ]);
}

function makeEncounterPageAppointment(string $patientId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT-ENC-PAGE-0001',
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'General Medicine',
        'scheduled_at' => '2026-05-21 09:00:00',
        'duration_minutes' => 30,
        'reason' => 'Follow-up visit',
        'notes' => null,
        'status' => 'checked_in',
        'status_reason' => null,
    ]);
}

it('renders the medical record archive page without the encounter workspace shell', function (): void {
    $user = makeEncounterPageUser(['medical.records.read']);

    $this->actingAs($user)
        ->get('/medical-records')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('medical-records/IndexV2')
            ->missing('encounterId')
            ->missing('encounterWorkspace'));
});

it('renders the encounter workspace page shell', function (): void {
    $user = makeEncounterPageUser([
        'medical.records.read',
        'medical.records.create',
    ]);
    $patient = makeEncounterPagePatient();
    $appointment = makeEncounterPageAppointment($patient->id);

    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC-PAGE-0001',
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'opened',
        'opened_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/encounters/'.$encounter->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('encounters/WorkspaceV2')
            ->where('encounterId', $encounter->id));
});

it('resolves an appointment to its encounter and redirects to the v2 workspace route', function (): void {
    $user = makeEncounterPageUser([
        'medical.records.read',
        'medical.records.create',
    ]);
    $patient = makeEncounterPagePatient();
    $appointment = makeEncounterPageAppointment($patient->id);

    $response = $this->actingAs($user)
        ->get('/encounters/by-appointment/'.$appointment->id)
        ->assertRedirect();

    $encounter = EncounterModel::query()->where('appointment_id', $appointment->id)->firstOrFail();
    expect($response->headers->get('Location'))->toContain('/encounters/'.$encounter->id);

    // Following the redirect lands on the v2 workspace shell, not the
    // deleted pre-cutover encounters/Show.vue page.
    $this->actingAs($user)
        ->get('/encounters/'.$encounter->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('encounters/WorkspaceV2')
            ->where('encounterId', $encounter->id));
});

it('creates the encounter on first resolution and reuses it on a second visit', function (): void {
    $user = makeEncounterPageUser([
        'medical.records.read',
        'medical.records.create',
    ]);
    $patient = makeEncounterPagePatient();
    $appointment = makeEncounterPageAppointment($patient->id);

    $first = $this->actingAs($user)
        ->get('/encounters/by-appointment/'.$appointment->id)
        ->assertRedirect();

    $second = $this->actingAs($user)
        ->get('/encounters/by-appointment/'.$appointment->id)
        ->assertRedirect();

    expect($second->headers->get('Location'))->toBe($first->headers->get('Location'));
    expect(EncounterModel::query()->where('appointment_id', $appointment->id)->count())->toBe(1);
});

it('resolves an encounter for an appointment through the encounters api', function (): void {
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeEncounterPageUser([
        'medical.records.read',
        'medical.records.create',
    ]);
    $patient = makeEncounterPagePatient();
    $appointment = makeEncounterPageAppointment($patient->id);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', [
            'patientId' => $patient->id,
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
            'encounterAt' => now()->toIso8601String(),
            'subjective' => 'Patient reports mild headache.',
            'objective' => 'Alert and oriented.',
            'assessment' => 'Tension headache.',
            'plan' => 'Hydration and rest.',
        ])
        ->assertCreated()
        ->json('data');

    $encounterId = $response['encounterId'];
    expect($encounterId)->not->toBeNull();

    $this->actingAs($user)
        ->getJson('/api/v1/encounters/by-appointment/'.$appointment->id)
        ->assertOk()
        ->assertJsonPath('data.id', $encounterId);
});
