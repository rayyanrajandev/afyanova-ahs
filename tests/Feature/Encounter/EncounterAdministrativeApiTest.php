<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterDiagnosisModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);
});

function makeEncounterAdminUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function makeEncounterAdminPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-ENCADM-'.strtoupper(Str::random(6)),
        'first_name' => 'Grace',
        'middle_name' => null,
        'last_name' => 'Mrema',
        'gender' => 'female',
        'date_of_birth' => '1988-02-10',
        'country_code' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Ilala',
        'status' => 'active',
    ]);
}

function makeEncounterAdminAppointment(string $patientId, string $department = 'Outpatient'): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT-ENCADM-'.strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => $department,
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'completed',
    ]);
}

function makeEncounterAdminAdmission(string $patientId): AdmissionModel
{
    return AdmissionModel::query()->create([
        'admission_number' => 'ADM-ENCADM-'.strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward B',
        'bed' => 'B-04',
        'admitted_at' => now()->subHours(3)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Observation',
        'status' => 'admitted',
    ]);
}

function encounterAdminMedicalRecordPayload(string $patientId, array $overrides = []): array
{
    return array_merge([
        'patientId' => $patientId,
        'encounterAt' => now()->toDateTimeString(),
        'recordType' => 'consultation_note',
        'subjective' => 'Reports feeling better',
        'objective' => 'Stable vitals',
        'assessment' => 'Improving',
        'plan' => 'Continue current plan',
    ], $overrides);
}

it('derives an inpatient encounter type when opened from an admission', function (): void {
    $user = makeEncounterAdminUser(['medical.records.read', 'medical.records.create']);
    $patient = makeEncounterAdminPatient();
    $admission = makeEncounterAdminAdmission($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', encounterAdminMedicalRecordPayload($patient->id, [
            'admissionId' => $admission->id,
            'recordType' => 'admission_note',
        ]))
        ->assertCreated()
        ->json('data');

    $encounter = EncounterModel::query()->findOrFail($created['encounterId']);
    expect($encounter->type)->toBe('inpatient');
});

it('derives an emergency encounter type from an appointment in the Emergency department', function (): void {
    $user = makeEncounterAdminUser(['medical.records.read', 'medical.records.create']);
    $patient = makeEncounterAdminPatient();
    $appointment = makeEncounterAdminAppointment($patient->id, 'Emergency');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', encounterAdminMedicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
        ]))
        ->assertCreated()
        ->json('data');

    $encounter = EncounterModel::query()->findOrFail($created['encounterId']);
    expect($encounter->type)->toBe('emergency');
});

it('derives an outpatient encounter type from a non-emergency appointment', function (): void {
    $user = makeEncounterAdminUser(['medical.records.read', 'medical.records.create']);
    $patient = makeEncounterAdminPatient();
    $appointment = makeEncounterAdminAppointment($patient->id, 'Outpatient');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', encounterAdminMedicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
        ]))
        ->assertCreated()
        ->json('data');

    $encounter = EncounterModel::query()->findOrFail($created['encounterId']);
    expect($encounter->type)->toBe('outpatient');
});

it('forbids adding an encounter diagnosis without medical.records.create', function (): void {
    $user = makeEncounterAdminUser(['medical.records.read']);
    $patient = makeEncounterAdminPatient();
    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC-ENCADM-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'status' => 'opened',
        'opened_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/encounters/{$encounter->id}/diagnoses", [
            'diagnosisCode' => 'A09',
        ])
        ->assertForbidden();
});

it('adds a diagnosis and auto-demotes the previous primary when a new primary is recorded', function (): void {
    $user = makeEncounterAdminUser(['medical.records.read', 'medical.records.create', 'medical.records.update']);
    $patient = makeEncounterAdminPatient();
    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC-ENCADM-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'status' => 'opened',
        'opened_at' => now(),
    ]);

    $first = $this->actingAs($user)
        ->postJson("/api/v1/encounters/{$encounter->id}/diagnoses", [
            'diagnosisCode' => 'R52',
            'diagnosisDescription' => 'Pain, unspecified',
            'diagnosisType' => 'primary',
        ])
        ->assertCreated()
        ->json('data');

    expect($first['diagnosisType'])->toBe('primary');

    $second = $this->actingAs($user)
        ->postJson("/api/v1/encounters/{$encounter->id}/diagnoses", [
            'diagnosisCode' => 'E11',
            'diagnosisDescription' => 'Type 2 diabetes mellitus',
            'diagnosisType' => 'primary',
        ])
        ->assertCreated()
        ->json('data');

    expect($second['diagnosisType'])->toBe('primary');

    $demoted = EncounterDiagnosisModel::query()->findOrFail($first['id']);
    expect($demoted->diagnosis_type)->toBe('secondary');

    $this->actingAs($user)
        ->deleteJson("/api/v1/encounters/{$encounter->id}/diagnoses/{$first['id']}")
        ->assertOk();

    expect(EncounterDiagnosisModel::query()->find($first['id']))->toBeNull();

    $workspace = $this->actingAs($user)
        ->getJson("/api/v1/encounters/{$encounter->id}?view=workspace")
        ->assertOk()
        ->json('data');

    expect($workspace['diagnoses'])->toHaveCount(1);
    expect($workspace['diagnoses'][0]['diagnosisCode'])->toBe('E11');
});

it('rejects an invalid disposition value when closing an encounter', function (): void {
    $user = makeEncounterAdminUser(['medical.records.read', 'medical.records.finalize']);
    $patient = makeEncounterAdminPatient();
    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC-ENCADM-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'status' => 'opened',
        'opened_at' => now(),
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/encounters/{$encounter->id}/status", [
            'status' => 'closed',
            'disposition' => 'not_a_real_value',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['disposition']);
});

it('syncs the note diagnosis code into the encounter diagnoses list when the note is finalized', function (): void {
    $user = makeEncounterAdminUser(['medical.records.read', 'medical.records.create', 'medical.records.finalize']);
    $patient = makeEncounterAdminPatient();
    $appointment = makeEncounterAdminAppointment($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', encounterAdminMedicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'diagnosisCode' => 'r52',
        ]))
        ->assertCreated()
        ->json('data');

    expect(EncounterDiagnosisModel::query()->where('encounter_id', $created['encounterId'])->count())->toBe(0);

    $this->actingAs($user)
        ->patchJson("/api/v1/medical-records/{$created['id']}/status", ['status' => 'finalized'])
        ->assertOk();

    $diagnoses = EncounterDiagnosisModel::query()->where('encounter_id', $created['encounterId'])->get();
    expect($diagnoses)->toHaveCount(1);
    expect($diagnoses->first()->diagnosis_code)->toBe('R52');
    expect($diagnoses->first()->diagnosis_type)->toBe('primary');
});

it('does not duplicate the synced diagnosis when the same note is finalized again', function (): void {
    $user = makeEncounterAdminUser(['medical.records.read', 'medical.records.create', 'medical.records.finalize', 'medical.records.amend']);
    $patient = makeEncounterAdminPatient();
    $appointment = makeEncounterAdminAppointment($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', encounterAdminMedicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'diagnosisCode' => 'R52',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson("/api/v1/medical-records/{$created['id']}/status", ['status' => 'finalized'])
        ->assertOk();

    // Re-finalizing an already-signed note lands as 'amended' (see EncounterLifecycleService),
    // but the sync should still treat it as "signing off" and not create a duplicate row.
    $this->actingAs($user)
        ->patchJson("/api/v1/medical-records/{$created['id']}/status", ['status' => 'finalized'])
        ->assertOk();

    expect(EncounterDiagnosisModel::query()->where('encounter_id', $created['encounterId'])->count())->toBe(1);
});

it('promotes a newly-signed diagnosis code to primary and demotes the previous one', function (): void {
    $user = makeEncounterAdminUser(['medical.records.read', 'medical.records.create']);
    $patient = makeEncounterAdminPatient();
    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC-ENCADM-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'status' => 'opened',
        'opened_at' => now(),
    ]);

    EncounterDiagnosisModel::query()->create([
        'encounter_id' => $encounter->id,
        'diagnosis_code' => 'E11',
        'diagnosis_type' => 'primary',
        'recorded_at' => now(),
    ]);

    app(\App\Modules\Encounter\Application\Services\EncounterLifecycleService::class)
        ->syncPrimaryDiagnosisFromMedicalRecord($encounter->id, 'R52', null);

    $diagnoses = EncounterDiagnosisModel::query()->where('encounter_id', $encounter->id)->get()->keyBy('diagnosis_code');
    expect($diagnoses['R52']->diagnosis_type)->toBe('primary');
    expect($diagnoses['E11']->diagnosis_type)->toBe('secondary');
});
