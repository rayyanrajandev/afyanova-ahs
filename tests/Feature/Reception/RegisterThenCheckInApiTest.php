<?php

use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Reproduces exactly what the reception registration form's "Save & Check In"
 * button does: POST /reception/patients, then POST /reception/walk-ins with the
 * id that came back.
 *
 * The existing walk-in coverage (ReceptionCheckInApiTest) builds its patient
 * with a model factory, so it never exercised the registration endpoint's own
 * output — which is the half the form actually feeds into check-in.
 */
function registrationClerk(): User
{
    $user = User::factory()->create();

    foreach ([
        'patients.create',
        'patients.read',
        'appointments.create',
        'appointments.read',
        'appointment.check-in',
    ] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'firstName' => 'Neema',
        'lastName' => 'Mushi',
        'gender' => 'female',
        'dateOfBirth' => '1990-04-02',
        'phone' => '+255712000777',
        'countryCode' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Kinondoni',
        'addressLine' => 'Mikocheni B, Plot 42',
    ], $overrides);
}

it('registers a patient and then checks them in, the way the form does', function (): void {
    $user = registrationClerk();

    $registerResponse = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients', registrationPayload());

    $registerResponse->assertSuccessful();

    $patientId = $registerResponse->json('data.id');
    expect($patientId)->not->toBeNull();

    $walkInResponse = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patientId,
            'arrivalMode' => 'walk_in',
            'reason' => 'Walk-in registration & check-in',
        ]);

    $walkInResponse->assertStatus(201);

    // The whole point of the button: the patient is actually in the queue.
    expect(AppointmentModel::query()->where('patient_id', $patientId)->exists())->toBeTrue();
});

it('gives a freshly registered patient a status that is eligible for check-in', function (): void {
    // PatientLookupService::isEligible() requires status === 'active'. If
    // registration ever produced anything else, "Save & Check In" would register
    // the patient and then fail the check-in half with a 422 — which is the
    // shape of the reported bug, so it is pinned here explicitly.
    $user = registrationClerk();

    $patientId = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients', registrationPayload())
        ->json('data.id');

    expect(PatientModel::query()->find($patientId)?->status)->toBe('active');
});

it('reports a real reason when check-in is refused rather than failing silently', function (): void {
    $user = registrationClerk();

    $patientId = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients', registrationPayload())
        ->json('data.id');

    $this->actingAs($user)->postJson('/api/v1/reception/walk-ins', [
        'patientId' => $patientId,
        'arrivalMode' => 'walk_in',
    ])->assertStatus(201);

    // Second walk-in while the first visit is still active — the endpoint must
    // say why, because the frontend now surfaces this message to the user
    // instead of showing a success toast.
    $conflict = $this->actingAs($user)->postJson('/api/v1/reception/walk-ins', [
        'patientId' => $patientId,
        'arrivalMode' => 'walk_in',
    ]);

    $conflict->assertStatus(422);
    expect($conflict->json('message'))->toBeString()->not->toBe('');
});

/**
 * Walk-in default routing (2026-08-16).
 *
 * Walk-in registration wrote `department => null` and nothing downstream ever
 * asked for one, so every walk-in reached the provider queue belonging to no
 * clinic — invisible on department-filtered boards, and unattributable for
 * department stock consumption. The decision taken: a walk-in defaults to
 * general outpatients and a nurse re-routes at triage if the patient needs a
 * different clinic, so routing is a change rather than a step to remember.
 */
function makeWalkInDepartment(array $overrides = []): object
{
    $id = (string) Str::uuid();

    DB::table('departments')->insert(array_merge([
        'id' => $id,
        'code' => 'OPD',
        'name' => 'Outpatient Department (OPD)',
        'service_type' => 'Clinical',
        'status' => 'active',
        'is_patient_facing' => true,
        'is_appointmentable' => true,
        'is_default_walk_in' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return DB::table('departments')->where('id', $id)->first();
}

it('lands an ordinary walk-in in the default department instead of nowhere', function (): void {
    $user = registrationClerk();
    $department = makeWalkInDepartment();

    $patientId = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients', registrationPayload())
        ->json('data.id');

    $this->actingAs($user)->postJson('/api/v1/reception/walk-ins', [
        'patientId' => $patientId,
        'arrivalMode' => 'walk_in',
    ])->assertStatus(201);

    $appointment = AppointmentModel::query()->where('patient_id', $patientId)->first();

    expect($appointment->department_id)->toBe($department->id)
        ->and($appointment->department)->toBe($department->name);
});

it('prefers the flagged default over any other routable department', function (): void {
    $user = registrationClerk();
    // Alphabetically first, and routable — but not the flagged default.
    makeWalkInDepartment(['code' => 'AAA', 'name' => 'A Clinic', 'is_default_walk_in' => false]);
    $expected = makeWalkInDepartment();

    $patientId = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients', registrationPayload())
        ->json('data.id');

    $this->actingAs($user)->postJson('/api/v1/reception/walk-ins', [
        'patientId' => $patientId,
        'arrivalMode' => 'walk_in',
    ])->assertStatus(201);

    expect(AppointmentModel::query()->where('patient_id', $patientId)->first()->department_id)
        ->toBe($expected->id);
});

function makeEmergencyDepartment(): object
{
    return makeWalkInDepartment([
        'code' => 'EMD',
        'name' => 'Emergency Department',
        'service_type' => 'Emergency',
        'is_default_walk_in' => false,
    ]);
}

it('routes an emergency arrival to the real emergency department', function (): void {
    $user = registrationClerk();
    makeWalkInDepartment();
    $emergency = makeEmergencyDepartment();

    $patientId = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients', registrationPayload())
        ->json('data.id');

    $this->actingAs($user)->postJson('/api/v1/reception/walk-ins', [
        'patientId' => $patientId,
        'arrivalMode' => 'emergency',
    ])->assertStatus(201);

    $appointment = AppointmentModel::query()->where('patient_id', $patientId)->first();

    expect($appointment->department_id)->toBe($emergency->id)
        ->and($appointment->department)->toBe('Emergency Department');
});

it('keeps emergency encounter typing working through the real department name', function (): void {
    // EncounterResolverService::deriveEncounterType() types the encounter by
    // str_contains(department, 'emergency'). Routing to a real row must not
    // silently downgrade an emergency visit to ambulatory.
    $user = registrationClerk();
    makeWalkInDepartment();
    makeEmergencyDepartment();

    $patientId = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients', registrationPayload())
        ->json('data.id');

    $this->actingAs($user)->postJson('/api/v1/reception/walk-ins', [
        'patientId' => $patientId,
        'arrivalMode' => 'emergency',
    ])->assertStatus(201);

    $appointment = AppointmentModel::query()->where('patient_id', $patientId)->first();

    expect(str_contains(strtolower((string) $appointment->department), 'emergency'))->toBeTrue();

    $encounter = \App\Modules\Encounter\Infrastructure\Models\EncounterModel::query()
        ->where('appointment_id', $appointment->id)
        ->first();

    expect($encounter)->not->toBeNull()
        ->and($encounter->type)->toBe('emergency');
});

it('falls back to the legacy label when a facility has no emergency department', function (): void {
    // Typing must never regress for a facility that has not been backfilled.
    $user = registrationClerk();
    makeWalkInDepartment();

    $patientId = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients', registrationPayload())
        ->json('data.id');

    $this->actingAs($user)->postJson('/api/v1/reception/walk-ins', [
        'patientId' => $patientId,
        'arrivalMode' => 'emergency',
    ])->assertStatus(201);

    $appointment = AppointmentModel::query()->where('patient_id', $patientId)->first();

    expect($appointment->department)->toBe('Emergency')
        ->and($appointment->department_id)->toBeNull();
});
