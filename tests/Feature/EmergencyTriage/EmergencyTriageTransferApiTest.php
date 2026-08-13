<?php

use App\Models\User;
use App\Modules\EmergencyTriage\Infrastructure\Models\EmergencyTriageCaseAuditLogModel;
use App\Modules\EmergencyTriage\Infrastructure\Models\EmergencyTriageCaseModel;
use App\Modules\EmergencyTriage\Infrastructure\Models\EmergencyTriageCaseTransferAuditLogModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function emergencyTransferGivePermissions(User $user, array $permissions): void
{
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }
}

function emergencyTransferMakePatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'middle_name' => null,
        'last_name' => 'Kweka',
        'gender' => 'female',
        'date_of_birth' => '1990-06-10',
        'phone' => '+255700002001',
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

function emergencyTransferMakeCase(string $patientId, int $clinicianUserId, array $overrides = []): EmergencyTriageCaseModel
{
    return EmergencyTriageCaseModel::query()->create(array_merge([
        'case_number' => 'ETC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patientId,
        'admission_id' => null,
        'appointment_id' => null,
        'assigned_clinician_user_id' => $clinicianUserId,
        'arrived_at' => now()->subHour()->toDateTimeString(),
        'triage_level' => 'red',
        'chief_complaint' => 'Severe chest pain',
        'vitals_summary' => 'BP 90/60',
        'triaged_at' => now()->subMinutes(45)->toDateTimeString(),
        'disposition_notes' => null,
        'completed_at' => null,
        'status' => 'triaged',
        'status_reason' => null,
    ], $overrides));
}

it('requires authentication for transfer list', function (): void {
    $this->getJson('/api/v1/emergency-triage-cases/'.Str::uuid().'/transfers')
        ->assertUnauthorized();
});

it('can create list and update emergency transfer when authorized', function (): void {
    $user = User::factory()->create();
    emergencyTransferGivePermissions($user, [
        'emergency.triage.read',
        'emergency.triage.manage-transfers',
    ]);

    $patient = emergencyTransferMakePatient();
    $clinician = User::factory()->create();
    $case = emergencyTransferMakeCase($patient->id, $clinician->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers', [
            'transferType' => 'internal',
            'priority' => 'urgent',
            'sourceLocation' => 'ER Bay 2',
            'destinationLocation' => 'ICU',
            'clinicalHandoffNotes' => 'Requires oxygen support',
            'transportMode' => 'wheelchair',
        ])
        ->assertCreated()
        ->assertJsonPath('data.transferType', 'internal')
        ->assertJsonPath('data.status', 'requested')
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $created['id']);

    $this->actingAs($user)
        ->getJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfer-status-counts')
        ->assertOk()
        ->assertJsonPath('data.requested', 1)
        ->assertJsonPath('data.total', 1);

    $this->actingAs($user)
        ->patchJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers/'.$created['id'].'/status', [
            'status' => 'accepted',
            'reason' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');
});

it('forbids emergency transfer creation without manage transfers permission', function (): void {
    $user = User::factory()->create();
    emergencyTransferGivePermissions($user, ['emergency.triage.read']);

    $patient = emergencyTransferMakePatient();
    $clinician = User::factory()->create();
    $case = emergencyTransferMakeCase($patient->id, $clinician->id);

    $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers', [
            'transferType' => 'external',
            'priority' => 'critical',
            'destinationLocation' => 'Regional Referral Hospital',
        ])
        ->assertForbidden();
});

it('allows emergency transfer audit logs and csv export when authorized', function (): void {
    $user = User::factory()->create();
    emergencyTransferGivePermissions($user, [
        'emergency.triage.manage-transfers',
        'emergency.triage.view-transfer-audit-logs',
    ]);

    $patient = emergencyTransferMakePatient();
    $clinician = User::factory()->create();
    $case = emergencyTransferMakeCase($patient->id, $clinician->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers', [
            'transferType' => 'internal',
            'priority' => 'urgent',
            'destinationLocation' => 'HDU',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers/'.$created['id'].'/status', [
            'status' => 'in_transit',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers/'.$created['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'emergency-triage-case.transfer.status.updated');

    $response = $this->actingAs($user)
        ->get('/api/v1/emergency-triage-cases/'.$case->id.'/transfers/'.$created['id'].'/audit-logs/export');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
});

it('forbids emergency transfer audit logs without transfer audit permission', function (): void {
    $user = User::factory()->create();
    emergencyTransferGivePermissions($user, ['emergency.triage.manage-transfers']);

    $patient = emergencyTransferMakePatient();
    $clinician = User::factory()->create();
    $case = emergencyTransferMakeCase($patient->id, $clinician->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers', [
            'transferType' => 'internal',
            'priority' => 'urgent',
            'destinationLocation' => 'HDU',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('writes emergency triage case status transition parity metadata in audit logs', function (): void {
    $user = User::factory()->create();
    emergencyTransferGivePermissions($user, [
        'emergency.triage.create',
        'emergency.triage.update-status',
        'emergency.triage.view-audit-logs',
    ]);

    $patient = emergencyTransferMakePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases', [
            'patientId' => $patient->id,
            'arrivalAt' => now()->subMinutes(15)->toDateTimeString(),
            'triageLevel' => 'yellow',
            'chiefComplaint' => 'Severe headache',
            'vitalsSummary' => 'BP 100/65',
        ])
        ->assertCreated()
        ->json('data');

    $bed = FacilityResourceModel::query()->create([
        'resource_type' => 'ward_bed',
        'code' => 'WB-OBS-1',
        'name' => 'Observation 1',
        'ward_name' => 'Observation',
        'bed_number' => '1',
        'location' => 'Admission registry',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/emergency-triage-cases/'.$created['id'].'/status', [
            'status' => 'admitted',
            'reason' => null,
            'dispositionNotes' => 'Admit for close monitoring and diagnostics.',
            'bedResourceId' => $bed->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'admitted');

    $statusAudit = EmergencyTriageCaseAuditLogModel::query()
        ->where('emergency_triage_case_id', $created['id'])
        ->where('action', 'emergency-triage-case.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();

    $metadata = $statusAudit?->metadata ?? [];
    expect($metadata['transition'] ?? [])->toMatchArray([
        'from' => 'waiting',
        'to' => 'admitted',
    ]);
    expect($metadata)->toMatchArray([
        'triage_timestamp_required' => false,
        'triage_timestamp_provided' => false,
        'completion_timestamp_required' => true,
        'completion_timestamp_provided' => true,
        'cancellation_reason_required' => false,
        'cancellation_reason_provided' => false,
        'disposition_notes_required' => true,
        'disposition_notes_provided' => true,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/emergency-triage-cases/'.$created['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'emergency-triage-case.status.updated');

    $response = $this->actingAs($user)
        ->get('/api/v1/emergency-triage-cases/'.$created['id'].'/audit-logs/export');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
});

it('writes emergency transfer status transition parity metadata in audit logs', function (): void {
    $user = User::factory()->create();
    emergencyTransferGivePermissions($user, [
        'emergency.triage.manage-transfers',
        'emergency.triage.view-transfer-audit-logs',
    ]);

    $patient = emergencyTransferMakePatient();
    $clinician = User::factory()->create();
    $case = emergencyTransferMakeCase($patient->id, $clinician->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers', [
            'transferType' => 'internal',
            'priority' => 'urgent',
            'destinationLocation' => 'ICU',
            'clinicalHandoffNotes' => 'Requires continuous oxygen support.',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers/'.$created['id'].'/status', [
            'status' => 'completed',
            'reason' => null,
            'clinicalHandoffNotes' => 'Transfer completed and accepted at destination.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $statusAudit = EmergencyTriageCaseTransferAuditLogModel::query()
        ->where('emergency_triage_case_transfer_id', $created['id'])
        ->where('action', 'emergency-triage-case.transfer.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();

    $metadata = $statusAudit?->metadata ?? [];
    expect($metadata['transition'] ?? [])->toMatchArray([
        'from' => 'requested',
        'to' => 'completed',
    ]);
    expect($metadata)->toMatchArray([
        'accepted_timestamp_required' => true,
        'accepted_timestamp_provided' => true,
        'departure_timestamp_required' => true,
        'departure_timestamp_provided' => true,
        'arrival_timestamp_required' => true,
        'arrival_timestamp_provided' => true,
        'completion_timestamp_required' => true,
        'completion_timestamp_provided' => true,
        'closure_reason_required' => false,
        'closure_reason_provided' => false,
    ]);
});

it('rejects transfer detail update when status fields are provided', function (): void {
    $user = User::factory()->create();
    emergencyTransferGivePermissions($user, ['emergency.triage.manage-transfers']);

    $patient = emergencyTransferMakePatient();
    $clinician = User::factory()->create();
    $case = emergencyTransferMakeCase($patient->id, $clinician->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers', [
            'transferType' => 'internal',
            'priority' => 'urgent',
            'destinationLocation' => 'ICU',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/emergency-triage-cases/'.$case->id.'/transfers/'.$created['id'], [
            'status' => 'completed',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});
