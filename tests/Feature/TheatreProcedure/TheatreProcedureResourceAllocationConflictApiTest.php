<?php

use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureResourceAllocationModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeTheatrePatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'middle_name' => null,
        'last_name' => 'Sanga',
        'gender' => 'female',
        'date_of_birth' => '1993-02-18',
        'phone' => '+255700000101',
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
    ], $overrides));
}

function makeTheatreProcedure(string $patientId, int $clinicianUserId, array $overrides = []): TheatreProcedureModel
{
    return TheatreProcedureModel::query()->create(array_merge([
        'procedure_number' => 'THR'.now()->format('Ymd').strtoupper(Str::random(5)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patientId,
        'admission_id' => null,
        'appointment_id' => null,
        'procedure_type' => 'surgery',
        'procedure_name' => 'Appendectomy',
        'operating_clinician_user_id' => $clinicianUserId,
        'anesthetist_user_id' => null,
        'theatre_room_name' => 'Theatre A',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'started_at' => null,
        'completed_at' => null,
        'status' => 'planned',
        'status_reason' => null,
        'notes' => null,
    ], $overrides));
}

function makeResourceManagerUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('theatre.procedures.manage-resources');

    return $user;
}

it('rejects resource allocation creation when same resource time window overlaps active allocation', function (): void {
    $user = makeResourceManagerUser();
    $patient = makeTheatrePatient();
    $clinician = User::factory()->create();
    $procedure = makeTheatreProcedure($patient->id, $clinician->id);

    TheatreProcedureResourceAllocationModel::query()->create([
        'theatre_procedure_id' => $procedure->id,
        'tenant_id' => null,
        'facility_id' => null,
        'resource_type' => 'room',
        'resource_reference' => 'THEATRE-A',
        'role_label' => null,
        'planned_start_at' => now()->addHours(1)->toDateTimeString(),
        'planned_end_at' => now()->addHours(2)->toDateTimeString(),
        'actual_start_at' => null,
        'actual_end_at' => null,
        'status' => 'scheduled',
        'status_reason' => null,
        'notes' => null,
        'metadata' => null,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations', [
            'resourceType' => 'room',
            'resourceReference' => 'THEATRE-A',
            'plannedStartAt' => now()->addHours(1)->addMinutes(30)->toDateTimeString(),
            'plannedEndAt' => now()->addHours(2)->addMinutes(30)->toDateTimeString(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['resourceReference']);

    expect(TheatreProcedureResourceAllocationModel::query()->where('theatre_procedure_id', $procedure->id)->count())->toBe(1);
});

it('rejects resource allocation update when changing to overlapping active window', function (): void {
    $user = makeResourceManagerUser();
    $patient = makeTheatrePatient();
    $clinician = User::factory()->create();
    $procedure = makeTheatreProcedure($patient->id, $clinician->id);

    $allocationOne = TheatreProcedureResourceAllocationModel::query()->create([
        'theatre_procedure_id' => $procedure->id,
        'tenant_id' => null,
        'facility_id' => null,
        'resource_type' => 'room',
        'resource_reference' => 'THEATRE-B',
        'role_label' => null,
        'planned_start_at' => now()->addHours(3)->toDateTimeString(),
        'planned_end_at' => now()->addHours(4)->toDateTimeString(),
        'actual_start_at' => null,
        'actual_end_at' => null,
        'status' => 'scheduled',
        'status_reason' => null,
        'notes' => null,
        'metadata' => null,
    ]);

    $allocationTwo = TheatreProcedureResourceAllocationModel::query()->create([
        'theatre_procedure_id' => $procedure->id,
        'tenant_id' => null,
        'facility_id' => null,
        'resource_type' => 'room',
        'resource_reference' => 'THEATRE-B',
        'role_label' => null,
        'planned_start_at' => now()->addHours(5)->toDateTimeString(),
        'planned_end_at' => now()->addHours(6)->toDateTimeString(),
        'actual_start_at' => null,
        'actual_end_at' => null,
        'status' => 'scheduled',
        'status_reason' => null,
        'notes' => null,
        'metadata' => null,
    ]);

    $originalStart = $allocationTwo->planned_start_at->toDateTimeString();
    $originalEnd = $allocationTwo->planned_end_at->toDateTimeString();

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations/'.$allocationTwo->id, [
            'plannedStartAt' => now()->addHours(3)->addMinutes(30)->toDateTimeString(),
            'plannedEndAt' => now()->addHours(4)->addMinutes(30)->toDateTimeString(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['resourceReference']);

    $allocationTwo->refresh();
    expect($allocationTwo->planned_start_at->toDateTimeString())->toBe($originalStart);
    expect($allocationTwo->planned_end_at->toDateTimeString())->toBe($originalEnd);
    expect($allocationOne->status)->toBe('scheduled');
});

it('rejects status activation when allocation overlaps another active allocation', function (): void {
    $user = makeResourceManagerUser();
    $patient = makeTheatrePatient();
    $clinician = User::factory()->create();
    $procedure = makeTheatreProcedure($patient->id, $clinician->id);

    TheatreProcedureResourceAllocationModel::query()->create([
        'theatre_procedure_id' => $procedure->id,
        'tenant_id' => null,
        'facility_id' => null,
        'resource_type' => 'equipment',
        'resource_reference' => 'VENT-02',
        'role_label' => null,
        'planned_start_at' => now()->addHours(7)->toDateTimeString(),
        'planned_end_at' => now()->addHours(8)->toDateTimeString(),
        'actual_start_at' => null,
        'actual_end_at' => null,
        'status' => 'scheduled',
        'status_reason' => null,
        'notes' => null,
        'metadata' => null,
    ]);

    $blockedAllocation = TheatreProcedureResourceAllocationModel::query()->create([
        'theatre_procedure_id' => $procedure->id,
        'tenant_id' => null,
        'facility_id' => null,
        'resource_type' => 'equipment',
        'resource_reference' => 'VENT-02',
        'role_label' => null,
        'planned_start_at' => now()->addHours(7)->addMinutes(15)->toDateTimeString(),
        'planned_end_at' => now()->addHours(8)->addMinutes(15)->toDateTimeString(),
        'actual_start_at' => null,
        'actual_end_at' => null,
        'status' => 'cancelled',
        'status_reason' => 'Initial cancellation',
        'notes' => null,
        'metadata' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations/'.$blockedAllocation->id.'/status', [
            'status' => 'scheduled',
            'reason' => null,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    $blockedAllocation->refresh();
    expect($blockedAllocation->status)->toBe('cancelled');
});
