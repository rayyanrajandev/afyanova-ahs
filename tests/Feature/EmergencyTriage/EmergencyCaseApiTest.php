<?php

use App\Models\User;
use App\Modules\EmergencyTriage\Infrastructure\Models\EmergencyTriageCaseModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coverage for the case list/create/status-count/status-transition
 * endpoints backing emergency/Queue.vue (Phases 1-2 of
 * reports/emergency-queue-modernization-plan.md). The existing
 * EmergencyTriageTransferApiTest.php exercises case creation only as
 * fixture setup for its own transfer-focused assertions — this file
 * covers the case endpoints themselves, which the new page depends on
 * directly.
 */
function emergencyCasePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTE'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Baraka', 'last_name' => 'Mushi',
        'gender' => 'male', 'date_of_birth' => '1985-03-15',
        'phone' => '+255700003001', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

/**
 * Postgres's LIKE is case-sensitive (unlike SQLite, which this test suite
 * runs on) — a plain where('chief_complaint', 'like', ...) silently
 * missed mixed-case matches in production. Fixed via
 * EloquentEmergencyTriageCaseRepository::applyCaseInsensitiveSearch().
 * Can't reproduce the case-sensitivity bug itself on SQLite, but this
 * documents and guards the intended case-insensitive behavior going
 * forward.
 */
it('finds emergency cases by search term regardless of case', function (): void {
    $user = makeUserWithRole(['emergency.triage.read', 'emergency.triage.create']);
    $patient = emergencyCasePatient();

    EmergencyTriageCaseModel::query()->create([
        'case_number' => 'ETC'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'arrived_at' => now(),
        'triage_level' => 'red',
        'chief_complaint' => 'Severe Abdominal Pain',
        'status' => 'waiting',
    ]);

    foreach (['abdominal', 'ABDOMINAL', 'AbDoMiNaL'] as $term) {
        $this->actingAs($user)
            ->getJson('/api/v1/emergency-triage-cases?q='.$term)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.chiefComplaint', 'Severe Abdominal Pain');
    }
});

it('creates an emergency case with the fields the V2 intake form sends', function (): void {
    $user = makeUserWithRole(['emergency.triage.read', 'emergency.triage.create']);
    $patient = emergencyCasePatient();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases', [
            'patientId' => $patient->id,
            'arrivalAt' => now()->subMinutes(10)->toDateTimeString(),
            'triageLevel' => 'red',
            'chiefComplaint' => 'Shortness of breath',
            'vitalsSummary' => 'SpO2 91%',
        ])
        ->assertCreated();

    $response->assertJsonPath('data.status', 'waiting');
    $response->assertJsonPath('data.triageLevel', 'red');
    $response->assertJsonPath('data.chiefComplaint', 'Shortness of breath');

    $caseId = $response->json('data.id');

    $this->actingAs($user)
        ->getJson('/api/v1/emergency-triage-cases')
        ->assertOk()
        ->assertJsonFragment(['id' => $caseId]);
});

it('rejects emergency case creation without a chief complaint or triage level', function (): void {
    $user = makeUserWithRole(['emergency.triage.read', 'emergency.triage.create']);
    $patient = emergencyCasePatient();

    $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases', [
            'patientId' => $patient->id,
            'arrivalAt' => now()->toDateTimeString(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['triageLevel', 'chiefComplaint']);
});

it('rejects creating a second active emergency case for a patient who already has one', function (): void {
    $user = makeUserWithRole(['emergency.triage.read', 'emergency.triage.create']);
    $patient = emergencyCasePatient();

    $first = $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases', [
            'patientId' => $patient->id,
            'arrivalAt' => now()->subMinutes(20)->toDateTimeString(),
            'triageLevel' => 'yellow',
            'chiefComplaint' => 'Twisted ankle',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases', [
            'patientId' => $patient->id,
            'arrivalAt' => now()->toDateTimeString(),
            'triageLevel' => 'green',
            'chiefComplaint' => 'Minor cut',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['patientId'])
        ->assertJsonPath('context.activeEmergencyCaseConflict.id', $first['id']);
});

it('allows a new emergency case once the patient\'s previous case reached a terminal status', function (): void {
    $user = makeUserWithRole(['emergency.triage.read', 'emergency.triage.create', 'emergency.triage.update-status']);
    $patient = emergencyCasePatient();

    $first = $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases', [
            'patientId' => $patient->id,
            'arrivalAt' => now()->subHours(2)->toDateTimeString(),
            'triageLevel' => 'green',
            'chiefComplaint' => 'Headache',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson("/api/v1/emergency-triage-cases/{$first['id']}/status", ['status' => 'discharged', 'dispositionNotes' => 'Sent home.'])
        ->assertOk();

    $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases', [
            'patientId' => $patient->id,
            'arrivalAt' => now()->toDateTimeString(),
            'triageLevel' => 'red',
            'chiefComplaint' => 'Chest pain',
        ])
        ->assertCreated();
});

it('returns status counts across the full lifecycle', function (): void {
    $user = makeUserWithRole(['emergency.triage.read']);
    $patient = emergencyCasePatient();

    EmergencyTriageCaseModel::query()->create([
        'case_number' => 'ETC'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'arrived_at' => now(),
        'triage_level' => 'yellow',
        'chief_complaint' => 'Ankle injury',
        'status' => 'waiting',
    ]);
    EmergencyTriageCaseModel::query()->create([
        'case_number' => 'ETC'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'arrived_at' => now(),
        'triage_level' => 'green',
        'chief_complaint' => 'Minor laceration',
        'status' => 'in_treatment',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/emergency-triage-cases/status-counts')
        ->assertOk();

    expect($response->json('data.waiting'))->toBe(1);
    expect($response->json('data.in_treatment'))->toBe(1);
    expect($response->json('data.total'))->toBe(2);
});

it('transitions a waiting case to triaged, then requires dispositionNotes and a bed to admit it', function (): void {
    $user = makeUserWithRole(['emergency.triage.read', 'emergency.triage.update-status', 'admissions.read']);
    $patient = emergencyCasePatient();
    $case = EmergencyTriageCaseModel::query()->create([
        'case_number' => 'ETC'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'arrived_at' => now(),
        'triage_level' => 'red',
        'chief_complaint' => 'Chest pain',
        'status' => 'waiting',
    ]);
    $bed = FacilityResourceModel::query()->create([
        'resource_type' => 'ward_bed',
        'code' => 'WB-CARDIO-1',
        'name' => 'Cardiology 1',
        'ward_name' => 'Cardiology',
        'bed_number' => '1',
        'location' => 'Admission registry',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/emergency-triage-cases/{$case->id}/status", ['status' => 'triaged'])
        ->assertOk()
        ->assertJsonPath('data.status', 'triaged');

    $this->actingAs($user)
        ->patchJson("/api/v1/emergency-triage-cases/{$case->id}/status", ['status' => 'admitted'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['dispositionNotes', 'bedResourceId']);

    $response = $this->actingAs($user)
        ->patchJson("/api/v1/emergency-triage-cases/{$case->id}/status", [
            'status' => 'admitted',
            'dispositionNotes' => 'Admitted to cardiology ward for observation.',
            'bedResourceId' => $bed->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'admitted');

    $admissionId = $response->json('data.admissionId');
    expect($admissionId)->not->toBeNull();

    $this->actingAs($user)
        ->getJson("/api/v1/admissions/{$admissionId}")
        ->assertOk()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.bedResourceId', $bed->id)
        ->assertJsonPath('data.status', 'admitted');
});

it('rejects admitting an emergency case into a bed already occupied by another admission', function (): void {
    $user = makeUserWithRole(['emergency.triage.read', 'emergency.triage.update-status', 'admissions.create']);
    $patientOne = emergencyCasePatient();
    $patientTwo = emergencyCasePatient();
    $bed = FacilityResourceModel::query()->create([
        'resource_type' => 'ward_bed',
        'code' => 'WB-ICU-1',
        'name' => 'ICU 1',
        'ward_name' => 'ICU',
        'bed_number' => '1',
        'location' => 'Admission registry',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', [
            'patientId' => $patientOne->id,
            'bedResourceId' => $bed->id,
            'admittedAt' => now()->toDateTimeString(),
        ])
        ->assertCreated();

    $case = EmergencyTriageCaseModel::query()->create([
        'case_number' => 'ETC'.strtoupper(Str::random(8)),
        'patient_id' => $patientTwo->id,
        'arrived_at' => now(),
        'triage_level' => 'red',
        'chief_complaint' => 'Trauma',
        'status' => 'in_treatment',
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/emergency-triage-cases/{$case->id}/status", [
            'status' => 'admitted',
            'dispositionNotes' => 'Admit to ICU.',
            'bedResourceId' => $bed->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bedResourceId']);

    $case->refresh();
    expect($case->status)->toBe('in_treatment');
    expect($case->admission_id)->toBeNull();
});

it('does not create a second admission when a case already carries a linked admission', function (): void {
    $user = makeUserWithRole(['emergency.triage.read', 'emergency.triage.update-status', 'admissions.create', 'admissions.read']);
    $patient = emergencyCasePatient();
    $bedOne = FacilityResourceModel::query()->create([
        'resource_type' => 'ward_bed', 'code' => 'WB-A-1', 'name' => 'A 1',
        'ward_name' => 'A', 'bed_number' => '1', 'location' => 'Admission registry', 'status' => 'active',
    ]);
    $bedTwo = FacilityResourceModel::query()->create([
        'resource_type' => 'ward_bed', 'code' => 'WB-A-2', 'name' => 'A 2',
        'ward_name' => 'A', 'bed_number' => '2', 'location' => 'Admission registry', 'status' => 'active',
    ]);

    $case = EmergencyTriageCaseModel::query()->create([
        'case_number' => 'ETC'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'arrived_at' => now(),
        'triage_level' => 'red',
        'chief_complaint' => 'Trauma',
        'status' => 'in_treatment',
    ]);

    $first = $this->actingAs($user)
        ->patchJson("/api/v1/emergency-triage-cases/{$case->id}/status", [
            'status' => 'admitted',
            'dispositionNotes' => 'Admit to ward A.',
            'bedResourceId' => $bedOne->id,
        ])
        ->assertOk()
        ->json('data');

    $second = $this->actingAs($user)
        ->patchJson("/api/v1/emergency-triage-cases/{$case->id}/status", [
            'status' => 'admitted',
            'dispositionNotes' => 'Re-admit attempt.',
            'bedResourceId' => $bedTwo->id,
        ])
        ->assertOk()
        ->json('data');

    expect($second['admissionId'])->toBe($first['admissionId']);
});

it('forbids emergency case status updates without emergency.triage.update-status', function (): void {
    $user = makeUserWithRole(['emergency.triage.read']);
    $patient = emergencyCasePatient();
    $case = EmergencyTriageCaseModel::query()->create([
        'case_number' => 'ETC'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'arrived_at' => now(),
        'triage_level' => 'green',
        'chief_complaint' => 'Sprain',
        'status' => 'waiting',
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/emergency-triage-cases/{$case->id}/status", ['status' => 'triaged'])
        ->assertForbidden();
});

it('forbids the emergency case list without emergency.triage.read', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/emergency-triage-cases')
        ->assertForbidden();
});
