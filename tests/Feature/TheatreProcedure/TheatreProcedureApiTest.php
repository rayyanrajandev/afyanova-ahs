<?php

use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureAuditLogModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureResourceAllocationAuditLogModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ensureActiveTheatreProcedureCatalogItem();
});

function theatreApiGivePermissions(User $user, array $permissions): void
{
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }
}

function theatreApiMakePatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Asha',
        'middle_name' => null,
        'last_name' => 'Banzi',
        'gender' => 'female',
        'date_of_birth' => '1991-03-20',
        'phone' => '+255700001001',
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

function theatreApiMakeProcedure(string $patientId, int $clinicianUserId, array $overrides = []): TheatreProcedureModel
{
    return TheatreProcedureModel::query()->create(array_merge([
        'procedure_number' => 'THR'.now()->format('Ymd').strtoupper(Str::random(5)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patientId,
        'admission_id' => null,
        'appointment_id' => null,
        'theatre_procedure_catalog_item_id' => ensureActiveTheatreProcedureCatalogItem()->id,
        'procedure_type' => 'THR-HRN-001',
        'procedure_name' => 'Hernia Repair',
        'operating_clinician_user_id' => $clinicianUserId,
        'anesthetist_user_id' => null,
        'theatre_room_name' => 'Theatre B',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'started_at' => null,
        'completed_at' => null,
        'status' => 'planned',
        'status_reason' => null,
        'notes' => null,
    ], $overrides));
}

function ensureActiveTheatreProcedureCatalogItem(array $overrides = []): ClinicalCatalogItemModel
{
    $attributes = array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'theatre_procedure',
        'code' => 'THR-HRN-001',
        'name' => 'Hernia Repair',
        'department_id' => null,
        'category' => 'surgery',
        'unit' => null,
        'description' => 'Open hernia repair',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides);

    $match = [
        'tenant_id' => $attributes['tenant_id'],
        'facility_id' => $attributes['facility_id'],
        'catalog_type' => $attributes['catalog_type'],
        'code' => $attributes['code'],
    ];

    unset($attributes['tenant_id'], $attributes['facility_id'], $attributes['catalog_type'], $attributes['code']);

    return ClinicalCatalogItemModel::query()->firstOrCreate($match, $attributes);
}

it('requires authentication for theatre procedure list', function (): void {
    $this->getJson('/api/v1/theatre-procedures')
        ->assertUnauthorized();
});

it('forbids theatre procedure list without read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/theatre-procedures')
        ->assertForbidden();
});

it('can create theatre procedure when user has create permission', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.create']);

    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();
    $catalogItem = ensureActiveTheatreProcedureCatalogItem();

    $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'procedureType' => 'THR-HRN-001',
            'procedureName' => 'Client override should be replaced',
            'operatingClinicianUserId' => $clinician->id,
            'theatreRoomName' => 'Theatre C',
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'notes' => 'Pre-op checklist pending',
        ])
        ->assertCreated()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.theatreProcedureCatalogItemId', $catalogItem->id)
        ->assertJsonPath('data.procedureType', 'THR-HRN-001')
        ->assertJsonPath('data.procedureName', 'Hernia Repair')
        ->assertJsonPath('data.status', 'planned');
});

it('returns no-recipe stock precheck state for theatre procedure show responses', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.create', 'theatre.procedures.read']);

    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/theatre-procedures/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.stockPrecheck.status', 'no_recipe')
        ->assertJsonPath('data.stockPrecheck.blocking', false)
        ->assertJsonPath('data.stockPrecheck.lineCount', 0);
});

it('forbids theatre procedure creation without create permission', function (): void {
    $user = User::factory()->create();
    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertForbidden();
});

it('creates theatre procedure from catalog item id without procedure type input', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.create']);
    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();
    $catalogItem = ensureActiveTheatreProcedureCatalogItem([
        'code' => 'THR-APP-010',
        'name' => 'Appendectomy',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'theatreProcedureCatalogItemId' => $catalogItem->id,
            'procedureType' => null,
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.theatreProcedureCatalogItemId', $catalogItem->id)
        ->assertJsonPath('data.procedureType', 'THR-APP-010')
        ->assertJsonPath('data.procedureName', 'Appendectomy');
});

it('rejects theatre procedure creation when procedure type is not in active catalog', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.create']);
    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'procedureType' => 'THR-UNKNOWN-999',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['procedureType']);
});

it('lists theatre procedures and status counts when user has read permission', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.read']);

    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();
    $procedure = theatreApiMakeProcedure($patient->id, $clinician->id, ['status' => 'planned']);

    $this->actingAs($user)
        ->getJson('/api/v1/theatre-procedures?status=planned')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $procedure->id)
        ->assertJsonPath('data.0.status', 'planned')
        ->assertJsonPath('data.0.patientLabel', 'Asha Banzi ('.$patient->patient_number.')');

    $this->actingAs($user)
        ->getJson('/api/v1/theatre-procedures/status-counts')
        ->assertOk()
        ->assertJsonPath('data.planned', 1)
        ->assertJsonPath('data.total', 1);
});

it('exposes current care flags for in-progress theatre procedures', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.create', 'theatre.procedures.update-status']);

    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$created['id'].'/status', [
            'status' => 'in_preop',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$created['id'].'/status', [
            'status' => 'in_progress',
            'reason' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.currentCare.isCurrent', true)
        ->assertJsonPath('data.currentCare.requiresReview', true)
        ->assertJsonPath('data.currentCare.isInProgress', true)
        ->assertJsonPath('data.currentCare.priorityRank', 500)
        ->assertJsonPath('data.currentCare.workflowHint', 'Procedure is in progress and still needs active monitoring.')
        ->assertJsonPath('data.currentCare.nextAction.key', 'review_case')
        ->assertJsonPath('data.currentCare.nextAction.label', 'Complete procedure')
        ->assertJsonPath('data.currentCare.nextAction.emphasis', 'primary');
});

it('allows theatre procedure audit logs and csv export when authorized', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, [
        'theatre.procedures.create',
        'theatre.procedures.update-status',
        'theatre.procedures.view-audit-logs',
    ]);

    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$created['id'].'/status', [
            'status' => 'in_preop',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/theatre-procedures/'.$created['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'theatre-procedure.status.updated');

    $response = $this->actingAs($user)
        ->get('/api/v1/theatre-procedures/'.$created['id'].'/audit-logs/export');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
});

it('forbids theatre procedure audit logs without permission', function (): void {
    $writer = User::factory()->create();
    theatreApiGivePermissions($writer, ['theatre.procedures.create']);

    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $created = $this->actingAs($writer)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($writer)
        ->getJson('/api/v1/theatre-procedures/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('updates theatre procedure and canonicalizes procedure fields when procedure type changes', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.create', 'theatre.procedures.update']);
    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();
    $newCatalogItem = ensureActiveTheatreProcedureCatalogItem([
        'code' => 'THR-CHL-004',
        'name' => 'Laparoscopic Cholecystectomy',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'entryMode' => 'draft',
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$created['id'], [
            'procedureType' => 'THR-CHL-004',
        ])
        ->assertOk()
        ->assertJsonPath('data.theatreProcedureCatalogItemId', $newCatalogItem->id)
        ->assertJsonPath('data.procedureType', 'THR-CHL-004')
        ->assertJsonPath('data.procedureName', 'Laparoscopic Cholecystectomy');
});

it('signs theatre draft procedures before workflow begins', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.create', 'theatre.procedures.update-status']);
    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'entryMode' => 'draft',
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.entryState', 'draft')
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures/'.$created['id'].'/sign')
        ->assertOk()
        ->assertJsonPath('data.entryState', 'active')
        ->assertJsonPath('data.signedByUserId', $user->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$created['id'].'/status', [
            'status' => 'in_preop',
        ])
        ->assertOk();
});

it('discards theatre draft procedures before signing and blocks deleting signed procedures', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.create', 'theatre.procedures.update-status']);
    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $draft = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'entryMode' => 'draft',
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->deleteJson('/api/v1/theatre-procedures/'.$draft['id'].'/draft')
        ->assertNoContent();

    $this->assertDatabaseMissing('theatre_procedures', [
        'id' => $draft['id'],
    ]);

    $signed = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'entryMode' => 'draft',
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures/'.$signed['id'].'/sign')
        ->assertOk();

    $this->actingAs($user)
        ->deleteJson('/api/v1/theatre-procedures/'.$signed['id'].'/draft')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order']);
});

it('blocks theatre status workflow while procedure remains draft', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.create', 'theatre.procedures.update-status']);
    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'entryMode' => 'draft',
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$created['id'].'/status', [
            'status' => 'in_preop',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order']);
});

it('writes theatre procedure status transition parity metadata in audit logs', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, [
        'theatre.procedures.create',
        'theatre.procedures.update-status',
        'theatre.procedures.view-audit-logs',
    ]);
    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$created['id'].'/status', [
            'status' => 'in_preop',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$created['id'].'/status', [
            'status' => 'in_progress',
            'startedAt' => now()->subMinutes(20)->toDateTimeString(),
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$created['id'].'/status', [
            'status' => 'completed',
            'completedAt' => now()->toDateTimeString(),
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $statusAudit = TheatreProcedureAuditLogModel::query()
        ->where('theatre_procedure_id', $created['id'])
        ->where('action', 'theatre-procedure.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();
    expect($statusAudit?->metadata ?? [])->toMatchArray([
        'completion_evidence_required' => true,
        'completion_evidence_provided' => true,
        'cancellation_reason_required' => false,
        'cancellation_reason_provided' => false,
    ]);
});

it('rejects backward theatre procedure status transitions', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, [
        'theatre.procedures.create',
        'theatre.procedures.update-status',
    ]);
    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$created['id'].'/status', [
            'status' => 'in_preop',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_preop');

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$created['id'].'/status', [
            'status' => 'planned',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('can create list and update theatre resource allocation with required permissions', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, [
        'theatre.procedures.read',
        'theatre.procedures.manage-resources',
    ]);

    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();
    $procedure = theatreApiMakeProcedure($patient->id, $clinician->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations', [
            'resourceType' => 'equipment',
            'resourceReference' => 'MONITOR-01',
            'roleLabel' => 'Cardiac monitor',
            'plannedStartAt' => now()->addHours(2)->toDateTimeString(),
            'plannedEndAt' => now()->addHours(3)->toDateTimeString(),
            'notes' => 'Reserve for procedure',
        ])
        ->assertCreated()
        ->assertJsonPath('data.resourceType', 'equipment')
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $created['id']);

    $this->actingAs($user)
        ->getJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocation-status-counts')
        ->assertOk()
        ->assertJsonPath('data.scheduled', 1)
        ->assertJsonPath('data.total', 1);

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations/'.$created['id'].'/status', [
            'status' => 'in_use',
            'reason' => null,
            'actualStartAt' => now()->addHours(2)->toDateTimeString(),
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_use');
});

it('forbids theatre resource allocation creation without manage resources permission', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.read']);

    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();
    $procedure = theatreApiMakeProcedure($patient->id, $clinician->id);

    $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations', [
            'resourceType' => 'room',
            'resourceReference' => 'THEATRE-X',
            'plannedStartAt' => now()->addHours(1)->toDateTimeString(),
            'plannedEndAt' => now()->addHours(2)->toDateTimeString(),
        ])
        ->assertForbidden();
});

it('allows resource allocation audit logs and csv export when authorized', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, [
        'theatre.procedures.manage-resources',
        'theatre.procedures.view-resource-audit-logs',
    ]);

    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();
    $procedure = theatreApiMakeProcedure($patient->id, $clinician->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations', [
            'resourceType' => 'staff',
            'resourceReference' => 'user:'.$clinician->id,
            'roleLabel' => 'Primary surgeon',
            'plannedStartAt' => now()->addHours(4)->toDateTimeString(),
            'plannedEndAt' => now()->addHours(5)->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations/'.$created['id'].'/status', [
            'status' => 'in_use',
            'reason' => null,
            'actualStartAt' => now()->addHours(4)->toDateTimeString(),
        ])
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations/'.$created['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'theatre-procedure.resource-allocation.status.updated');

    $response = $this->actingAs($user)
        ->get('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations/'.$created['id'].'/audit-logs/export');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
});

it('writes resource allocation status transition parity metadata in audit logs', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, [
        'theatre.procedures.manage-resources',
        'theatre.procedures.view-resource-audit-logs',
    ]);

    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();
    $procedure = theatreApiMakeProcedure($patient->id, $clinician->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations', [
            'resourceType' => 'equipment',
            'resourceReference' => 'SUCTION-02',
            'plannedStartAt' => now()->addHours(3)->toDateTimeString(),
            'plannedEndAt' => now()->addHours(5)->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations/'.$created['id'].'/status', [
            'status' => 'released',
            'actualStartAt' => now()->addHours(3)->toDateTimeString(),
            'actualEndAt' => now()->addHours(5)->toDateTimeString(),
        ])
        ->assertOk();

    $statusAudit = TheatreProcedureResourceAllocationAuditLogModel::query()
        ->where('theatre_procedure_resource_allocation_id', $created['id'])
        ->where('action', 'theatre-procedure.resource-allocation.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();
    expect($statusAudit?->metadata ?? [])->toMatchArray([
        'release_end_time_required' => true,
        'release_end_time_provided' => true,
        'cancellation_reason_required' => false,
        'cancellation_reason_provided' => false,
    ]);
});

it('applies theatre entered-in-error lifecycle action and records audit metadata', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.create']);
    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures', [
            'patientId' => $patient->id,
            'procedureType' => 'THR-HRN-001',
            'operatingClinicianUserId' => $clinician->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures/'.$created['id'].'/lifecycle', [
            'action' => 'entered_in_error',
            'reason' => 'Scheduled in wrong patient chart.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.lifecycleReasonCode', 'entered_in_error')
        ->assertJsonPath('data.statusReason', 'Scheduled in wrong patient chart.');

    $row = TheatreProcedureModel::query()->findOrFail($created['id']);
    expect($row->status)->toBe('cancelled');
    expect($row->lifecycle_reason_code)->toBe('entered_in_error');
    expect($row->entered_in_error_at)->not->toBeNull();

    $audit = TheatreProcedureAuditLogModel::query()
        ->where('theatre_procedure_id', $created['id'])
        ->where('action', 'theatre-procedure.lifecycle.entered-in-error')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit?->metadata['lifecycle_action'] ?? null)->toBe('entered_in_error');
});

it('rejects theatre cancel lifecycle action when procedure is already completed', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.create']);
    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();

    $procedure = theatreApiMakeProcedure($patient->id, $clinician->id, [
        'status' => 'completed',
        'completed_at' => now()->subMinutes(10)->toDateTimeString(),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures/'.$procedure->id.'/lifecycle', [
            'action' => 'cancel',
            'reason' => 'No longer required.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['action']);
});

it('forbids resource allocation audit logs without permission', function (): void {
    $user = User::factory()->create();
    theatreApiGivePermissions($user, ['theatre.procedures.manage-resources']);

    $patient = theatreApiMakePatient();
    $clinician = User::factory()->create();
    $procedure = theatreApiMakeProcedure($patient->id, $clinician->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations', [
            'resourceType' => 'room',
            'resourceReference' => 'THEATRE-Z',
            'plannedStartAt' => now()->addHours(6)->toDateTimeString(),
            'plannedEndAt' => now()->addHours(7)->toDateTimeString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/theatre-procedures/'.$procedure->id.'/resource-allocations/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});
