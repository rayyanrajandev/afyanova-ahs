<?php

use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Support\CanonicalEncounterState\CanonicalEncounterConflictCode;
use App\Support\CanonicalEncounterState\CanonicalEncounterState;
use App\Support\CanonicalEncounterState\CanonicalEncounterStateResolver;
use App\Support\CanonicalEncounterState\CanonicalNoteDimension;
use App\Support\CanonicalEncounterState\CanonicalOrdersDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Simple test utility for the Shadow Mode resolver: given an encounter fixture
 * (built directly against the existing Eloquent models, no HTTP/permission
 * layer involved — this exercises the resolver in isolation, not the API),
 * assert the resolved canonical snapshot. Not a full test suite.
 */
function makeCanonicalStatePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Zainab',
        'middle_name' => null,
        'last_name' => 'Hassan',
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
    ]);
}

function makeCanonicalStateEncounter(string $patientId, array $overrides = []): EncounterModel
{
    return EncounterModel::query()->create(array_merge([
        'encounter_number' => 'ENC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'status' => 'opened',
        'opened_at' => now()->subHour(),
    ], $overrides));
}

function makeCanonicalStateMedicalRecord(string $patientId, string $encounterId, array $overrides = []): MedicalRecordModel
{
    return MedicalRecordModel::query()->create(array_merge([
        'record_number' => 'MR'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'encounter_at' => now()->subHour(),
        'record_type' => 'consultation_note',
        'subjective' => 'Patient reports headache.',
        'status' => 'draft',
    ], $overrides));
}

function makeCanonicalStateLabOrder(string $patientId, string $encounterId, array $overrides = []): LaboratoryOrderModel
{
    return LaboratoryOrderModel::query()->create(array_merge([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'ordered_at' => now()->subMinutes(30),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'status' => 'ordered',
        'entry_state' => 'active',
    ], $overrides));
}

it('resolves a brand-new encounter with no note and no orders as REGISTERED', function (): void {
    $patient = makeCanonicalStatePatient();
    $encounter = makeCanonicalStateEncounter($patient->id);

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);

    expect($snapshot)->not->toBeNull();
    expect($snapshot->canonicalState)->toBe(CanonicalEncounterState::REGISTERED);
    expect($snapshot->noteDimension)->toBe(CanonicalNoteDimension::NONE);
    expect($snapshot->ordersDimension)->toBe(CanonicalOrdersDimension::NONE);
    expect($snapshot->matchedRuleId)->toBe('RULE-3');
    expect($snapshot->detectedConflicts)->toBe([]);
});

it('resolves a draft note with no orders as IN_CONSULTATION', function (): void {
    $patient = makeCanonicalStatePatient();
    $encounter = makeCanonicalStateEncounter($patient->id, ['status' => 'in_progress']);
    makeCanonicalStateMedicalRecord($patient->id, $encounter->id);

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);

    expect($snapshot->canonicalState)->toBe(CanonicalEncounterState::IN_CONSULTATION);
    expect($snapshot->noteDimension)->toBe(CanonicalNoteDimension::DRAFT);
    expect($snapshot->matchedRuleId)->toBe('RULE-4A');
});

it('detects CONFLICT-04 when a draft note carries a non-null signed_at', function (): void {
    $patient = makeCanonicalStatePatient();
    $encounter = makeCanonicalStateEncounter($patient->id, ['status' => 'in_progress']);
    makeCanonicalStateMedicalRecord($patient->id, $encounter->id, [
        'status' => 'draft',
        'signed_at' => now()->subMinutes(10),
        'signed_by_user_id' => null,
    ]);

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);

    $codes = array_column($snapshot->detectedConflicts, 'code');
    expect($codes)->toContain(CanonicalEncounterConflictCode::CONFLICT_04->value);
});

it('detects CONFLICT-01 when an encounter is closed with a pending lab order', function (): void {
    $patient = makeCanonicalStatePatient();
    $encounter = makeCanonicalStateEncounter($patient->id, ['status' => 'closed', 'closed_at' => now()]);
    makeCanonicalStateMedicalRecord($patient->id, $encounter->id, ['status' => 'finalized', 'signed_at' => now()]);
    makeCanonicalStateLabOrder($patient->id, $encounter->id, ['status' => 'ordered']);

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);

    expect($snapshot->canonicalState)->toBe(CanonicalEncounterState::CLOSED);
    $codes = array_column($snapshot->detectedConflicts, 'code');
    expect($codes)->toContain(CanonicalEncounterConflictCode::CONFLICT_01->value);
});

it('returns null for an encounter id that does not exist', function (): void {
    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve((string) Str::uuid());

    expect($snapshot)->toBeNull();
});
