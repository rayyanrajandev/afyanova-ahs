<?php

use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeGenderPlacementPatient(string $gender): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTGP'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Test',
        'last_name' => 'Patient',
        'gender' => $gender,
        'date_of_birth' => '1992-01-01',
        'phone' => '+2557000001'.random_int(10, 99),
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function seedGenderPlacementResource(string $resourceType, ?string $genderRestriction, string $code): FacilityResourceModel
{
    return FacilityResourceModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'resource_type' => $resourceType,
        'code' => strtoupper($code),
        'name' => 'Placement Resource '.strtoupper($code),
        'department_id' => null,
        'service_point_type' => $resourceType === 'service_point' ? 'triage' : null,
        'ward_name' => $resourceType === 'service_point' ? null : 'Room Block',
        'bed_number' => $resourceType === 'service_point' ? null : '01',
        'gender_restriction' => $genderRestriction,
        'location' => 'Test wing',
        'status' => 'active',
        'status_reason' => null,
        'notes' => 'Seeded for gender placement tests',
    ]);
}

function makeGenderPlacementAdmissionUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('admissions.create');

    return $user;
}

function genderPlacementPayload(string $patientId, string $bedResourceId): array
{
    return [
        'patientId' => $patientId,
        'admittedAt' => now()->toDateTimeString(),
        'bedResourceId' => $bedResourceId,
        'admissionReason' => 'Observation',
        'notes' => 'Gender placement test',
    ];
}

it('rejects admission placement into a gender-restricted observation room when patient gender does not match', function (): void {
    $user = makeGenderPlacementAdmissionUser();
    $patient = makeGenderPlacementPatient('female');
    $room = seedGenderPlacementResource('observation_room', 'male', 'OBS-GP-001');

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', genderPlacementPayload($patient->id, $room->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bedResourceId']);
});

it('accepts admission placement into a gender-restricted observation room when patient gender matches', function (): void {
    $user = makeGenderPlacementAdmissionUser();
    $patient = makeGenderPlacementPatient('female');
    $room = seedGenderPlacementResource('observation_room', 'female', 'OBS-GP-002');

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', genderPlacementPayload($patient->id, $room->id))
        ->assertCreated()
        ->assertJsonPath('data.bedResourceId', $room->id);
});

it('accepts placement into a room with no gender restriction regardless of patient gender', function (): void {
    $user = makeGenderPlacementAdmissionUser();
    $patient = makeGenderPlacementPatient('male');
    $room = seedGenderPlacementResource('observation_room', null, 'OBS-GP-003');

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', genderPlacementPayload($patient->id, $room->id))
        ->assertCreated()
        ->assertJsonPath('data.bedResourceId', $room->id);
});

it('accepts placement into an existing ward-bed with no gender restriction, unaffected by the new check', function (): void {
    $user = makeGenderPlacementAdmissionUser();
    $patient = makeGenderPlacementPatient('female');
    $bed = seedGenderPlacementResource('ward_bed', null, 'WB-GP-001');

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', genderPlacementPayload($patient->id, $bed->id))
        ->assertCreated()
        ->assertJsonPath('data.bedResourceId', $bed->id);
});

it('rejects placement into a resource type that is not ward-bed or observation-room', function (): void {
    $user = makeGenderPlacementAdmissionUser();
    $patient = makeGenderPlacementPatient('female');
    $servicePoint = seedGenderPlacementResource('service_point', null, 'SP-GP-001');

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', genderPlacementPayload($patient->id, $servicePoint->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bedResourceId']);
});
