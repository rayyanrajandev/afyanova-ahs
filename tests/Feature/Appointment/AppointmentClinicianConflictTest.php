<?php

use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coverage for the patient flow redesign's appointment workflow A3: a hard
 * block on two appointments overlapping in time for the same clinician.
 * Mirrors AppointmentApiTest.php's patient-conflict coverage
 * (assertNoActiveSameDayConflict) but for the new clinician-side guard
 * (assertNoClinicianScheduleConflict in Create/UpdateAppointmentUseCase).
 */
function clinicianConflictUser(): User
{
    $user = User::factory()->create();
    foreach (['appointments.read', 'appointments.create', 'appointments.update'] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function clinicianConflictPatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PTCC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Clinician',
        'last_name' => 'Conflict',
        'gender' => 'male',
        'date_of_birth' => '1985-01-01',
        'phone' => '+255700000099',
        'country_code' => 'TZ',
        'status' => 'active',
    ], $overrides));
}

it('blocks creating an overlapping appointment for the same clinician', function (): void {
    $actor = clinicianConflictUser();
    $clinician = User::factory()->create();
    $patientOne = clinicianConflictPatient();
    $patientTwo = clinicianConflictPatient(['phone' => '+255700000098']);
    $start = now()->addDay()->setTime(9, 0);

    $this->actingAs($actor)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientOne->id,
            'scheduledAt' => $start->toDateTimeString(),
            'durationMinutes' => 30,
            'clinicianUserId' => $clinician->id,
        ])
        ->assertCreated();

    $response = $this->actingAs($actor)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientTwo->id,
            'scheduledAt' => $start->copy()->addMinutes(15)->toDateTimeString(),
            'durationMinutes' => 30,
            'clinicianUserId' => $clinician->id,
        ])
        ->assertStatus(422);

    expect($response->json('errors.clinicianUserId'))->not->toBeNull();
    expect($response->json('context.clinicianScheduleConflict'))->not->toBeNull();
});

it('allows back-to-back non-overlapping appointments for the same clinician', function (): void {
    $actor = clinicianConflictUser();
    $clinician = User::factory()->create();
    $patientOne = clinicianConflictPatient();
    $patientTwo = clinicianConflictPatient(['phone' => '+255700000097']);
    $start = now()->addDay()->setTime(9, 0);

    $this->actingAs($actor)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientOne->id,
            'scheduledAt' => $start->toDateTimeString(),
            'durationMinutes' => 30,
            'clinicianUserId' => $clinician->id,
        ])
        ->assertCreated();

    $this->actingAs($actor)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientTwo->id,
            'scheduledAt' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'durationMinutes' => 30,
            'clinicianUserId' => $clinician->id,
        ])
        ->assertCreated();
});

it('does not block overlapping appointments when no clinician is assigned', function (): void {
    $actor = clinicianConflictUser();
    $patientOne = clinicianConflictPatient();
    $patientTwo = clinicianConflictPatient(['phone' => '+255700000096']);
    $start = now()->addDay()->setTime(9, 0);

    $this->actingAs($actor)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientOne->id,
            'scheduledAt' => $start->toDateTimeString(),
            'durationMinutes' => 30,
        ])
        ->assertCreated();

    $this->actingAs($actor)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientTwo->id,
            'scheduledAt' => $start->copy()->addMinutes(15)->toDateTimeString(),
            'durationMinutes' => 30,
        ])
        ->assertCreated();
});

it('blocks reassigning an appointment to a clinician who is already double-booked at that time', function (): void {
    $actor = clinicianConflictUser();
    $clinician = User::factory()->create();
    $patientOne = clinicianConflictPatient();
    $patientTwo = clinicianConflictPatient(['phone' => '+255700000095']);
    $start = now()->addDay()->setTime(9, 0);

    $this->actingAs($actor)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientOne->id,
            'scheduledAt' => $start->toDateTimeString(),
            'durationMinutes' => 30,
            'clinicianUserId' => $clinician->id,
        ])
        ->assertCreated();

    $second = $this->actingAs($actor)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientTwo->id,
            'scheduledAt' => $start->toDateTimeString(),
            'durationMinutes' => 30,
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/appointments/'.$second['id'], [
            'clinicianUserId' => $clinician->id,
        ])
        ->assertStatus(422);
});

it('allows updating an appointment that keeps its own existing clinician slot', function (): void {
    $actor = clinicianConflictUser();
    $clinician = User::factory()->create();
    $patient = clinicianConflictPatient();
    $start = now()->addDay()->setTime(9, 0);

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => $start->toDateTimeString(),
            'durationMinutes' => 30,
            'clinicianUserId' => $clinician->id,
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/appointments/'.$created['id'], [
            'reason' => 'Updated reason',
        ])
        ->assertOk();
});
