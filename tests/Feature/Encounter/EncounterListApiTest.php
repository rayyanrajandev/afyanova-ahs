<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware([
        PreventRequestForgery::class,
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);
});

function makeEncounterListActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function makeEncounterListPatient(string $firstName = 'Asha', string $lastName = 'Kimaro'): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-ENC-LIST-'.strtoupper(Str::random(6)),
        'first_name' => $firstName,
        'middle_name' => null,
        'last_name' => $lastName,
        'gender' => 'female',
        'date_of_birth' => '1990-06-18',
        'country_code' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Ilala',
        'status' => 'active',
    ]);
}

function makeEncounterListEncounter(PatientModel $patient, string $status = 'opened'): EncounterModel
{
    return EncounterModel::query()->create([
        'encounter_number' => 'ENC-LIST-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'status' => $status,
        'opened_at' => now(),
    ]);
}

it('forbids listing encounters without medical.records.read', function (): void {
    $actor = makeEncounterListActor([]);

    $this->actingAs($actor)
        ->getJson('/api/v1/encounters')
        ->assertForbidden();
});

it('lists encounters with patient name and note status embedded', function (): void {
    $actor = makeEncounterListActor(['medical.records.read']);
    $patient = makeEncounterListPatient();
    $encounter = makeEncounterListEncounter($patient);

    MedicalRecordModel::query()->create([
        'record_number' => 'MR-LIST-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'encounter_at' => now(),
        'record_type' => 'consultation_note',
        'status' => 'draft',
    ]);

    $response = $this->actingAs($actor)
        ->getJson('/api/v1/encounters')
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $encounter->id);
    expect($row)->not->toBeNull();
    expect($row['patientName'])->toBe('Asha Kimaro');
    expect($row['encounterNumber'])->toBe($encounter->encounter_number);
    expect($row['hasMedicalRecord'])->toBeTrue();
    expect($row['latestMedicalRecordStatus'])->toBe('draft');
    expect($row['latestMedicalRecordType'])->toBe('consultation_note');
});

it('reports no medical record for an encounter that has none yet', function (): void {
    $actor = makeEncounterListActor(['medical.records.read']);
    $patient = makeEncounterListPatient();
    $encounter = makeEncounterListEncounter($patient);

    $response = $this->actingAs($actor)
        ->getJson('/api/v1/encounters')
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $encounter->id);
    expect($row['hasMedicalRecord'])->toBeFalse();
    expect($row['latestMedicalRecordStatus'])->toBeNull();
});

it('only attaches the latest medical record when an encounter has several', function (): void {
    $actor = makeEncounterListActor(['medical.records.read']);
    $patient = makeEncounterListPatient();
    $encounter = makeEncounterListEncounter($patient);

    // created_at is auto-managed by Eloquent (any explicit value here would
    // be overwritten on save), so the two records are distinguished by
    // creation order alone — the repository's id tie-break (see
    // EloquentEncounterRepository::latestMedicalRecordsByEncounterId) is
    // exactly what's under test here.
    MedicalRecordModel::query()->create([
        'record_number' => 'MR-OLD-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'encounter_at' => now()->subHour(),
        'record_type' => 'consultation_note',
        'status' => 'finalized',
    ]);
    MedicalRecordModel::query()->create([
        'record_number' => 'MR-NEW-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'encounter_at' => now(),
        'record_type' => 'referral_note',
        'status' => 'draft',
    ]);

    $response = $this->actingAs($actor)
        ->getJson('/api/v1/encounters')
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $encounter->id);
    expect($row['latestMedicalRecordType'])->toBe('referral_note');
    expect($row['latestMedicalRecordStatus'])->toBe('draft');
});

it('filters encounters by status', function (): void {
    $actor = makeEncounterListActor(['medical.records.read']);
    $patient = makeEncounterListPatient();
    $opened = makeEncounterListEncounter($patient, 'opened');
    $closed = makeEncounterListEncounter($patient, 'closed');

    $response = $this->actingAs($actor)
        ->getJson('/api/v1/encounters?status=closed')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($closed->id);
    expect($ids)->not->toContain($opened->id);
});

it('searches encounters by patient name', function (): void {
    $actor = makeEncounterListActor(['medical.records.read']);
    $target = makeEncounterListPatient('Zawadi', 'Mushi');
    $other = makeEncounterListPatient('Baraka', 'Ndosi');
    $targetEncounter = makeEncounterListEncounter($target);
    makeEncounterListEncounter($other);

    $response = $this->actingAs($actor)
        ->getJson('/api/v1/encounters?q=Zawadi')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($targetEncounter->id);
    expect($ids)->toHaveCount(1);
});

it('returns encounter status counts', function (): void {
    $actor = makeEncounterListActor(['medical.records.read']);
    $patient = makeEncounterListPatient();
    makeEncounterListEncounter($patient, 'opened');
    makeEncounterListEncounter($patient, 'closed');
    makeEncounterListEncounter($patient, 'closed');

    $response = $this->actingAs($actor)
        ->getJson('/api/v1/encounters/status-counts')
        ->assertOk();

    expect($response->json('data.opened'))->toBe(1);
    expect($response->json('data.closed'))->toBe(2);
    expect($response->json('data.total'))->toBe(3);
});
