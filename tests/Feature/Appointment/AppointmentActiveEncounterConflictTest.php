<?php

use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\EmergencyTriage\Infrastructure\Models\EmergencyTriageCaseModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * P7 of the Reception/Emergency/Admission/Bed-Management audit
 * follow-through (deferred from P6): blocks booking a new appointment or
 * walk-in for a patient who currently has an active Emergency case or
 * Admission — CreateAppointmentUseCase::assertNoActivePatientEncounterConflict().
 * Hard-blocked per the user's explicit decision, matching every other
 * conflict check in this use case (same shape as
 * assertNoActiveSameDayConflict's ActiveAppointmentConflictException).
 */
function encounterConflictPatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PTEC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Encounter',
        'last_name' => 'Conflict',
        'gender' => 'female',
        'date_of_birth' => '1988-06-01',
        'phone' => '+255700000201',
        'country_code' => 'TZ',
        'status' => 'active',
    ], $overrides));
}

it('blocks creating an appointment for a patient with an active emergency case', function (): void {
    $user = makeUserWithRole(['appointments.read', 'appointments.create']);
    $patient = encounterConflictPatient();

    $case = EmergencyTriageCaseModel::query()->create([
        'case_number' => 'ETC'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'arrived_at' => now(),
        'triage_level' => 'yellow',
        'chief_complaint' => 'Twisted ankle',
        'status' => 'in_treatment',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
        ])
        ->assertStatus(422);

    expect($response->json('errors.patientId'))->not->toBeNull();
    $response->assertJsonPath('context.activePatientEncounterConflict.conflictType', 'emergency_case');
    $response->assertJsonPath('context.activePatientEncounterConflict.record.id', $case->id);
});

it('blocks creating an appointment for a patient with an active admission', function (): void {
    $user = makeUserWithRole(['appointments.read', 'appointments.create']);
    $patient = encounterConflictPatient(['phone' => '+255700000202']);

    $admission = AdmissionModel::query()->create([
        'admission_number' => 'ADM'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward A',
        'bed' => 'A-01',
        'admitted_at' => now()->subHours(2)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Observation',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
        ])
        ->assertStatus(422);

    expect($response->json('errors.patientId'))->not->toBeNull();
    $response->assertJsonPath('context.activePatientEncounterConflict.conflictType', 'admission');
    $response->assertJsonPath('context.activePatientEncounterConflict.record.id', $admission->id);
});

it('allows creating an appointment once the emergency case reaches a terminal status', function (): void {
    $user = makeUserWithRole(['appointments.read', 'appointments.create']);
    $patient = encounterConflictPatient(['phone' => '+255700000203']);

    EmergencyTriageCaseModel::query()->create([
        'case_number' => 'ETC'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'arrived_at' => now()->subHours(3),
        'triage_level' => 'green',
        'chief_complaint' => 'Minor cut',
        'status' => 'discharged',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
        ])
        ->assertCreated();
});

it('allows creating an appointment once the admission is discharged', function (): void {
    $user = makeUserWithRole(['appointments.read', 'appointments.create']);
    $patient = encounterConflictPatient(['phone' => '+255700000204']);

    AdmissionModel::query()->create([
        'admission_number' => 'ADM'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward B',
        'bed' => 'B-02',
        'admitted_at' => now()->subDays(2)->toDateTimeString(),
        'discharged_at' => now()->subDay()->toDateTimeString(),
        'admission_reason' => 'Observation',
        'notes' => null,
        'status' => 'discharged',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
        ])
        ->assertCreated();
});

it('blocks registering a walk-in for a patient with an active admission', function (): void {
    $user = makeUserWithRole(['appointments.read', 'appointments.create', 'appointments.update-status']);
    $patient = encounterConflictPatient(['phone' => '+255700000205']);

    AdmissionModel::query()->create([
        'admission_number' => 'ADM'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward C',
        'bed' => 'C-03',
        'admitted_at' => now()->subHours(5)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Observation',
        'notes' => null,
        'status' => 'transferred',
        'status_reason' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'walk_in',
        ])
        ->assertStatus(422);

    $response->assertJsonPath('data.activePatientEncounterConflict.conflictType', 'admission');
});
