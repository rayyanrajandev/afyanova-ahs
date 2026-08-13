<?php

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Models\User;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Radiology\Domain\Events\RadiologyOrderCompleted;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderAuditLogModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ensureActiveRadiologyProcedureCatalogItem();
});

function makeRadiologyPatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'middle_name' => null,
        'last_name' => 'Kweka',
        'gender' => 'female',
        'date_of_birth' => '1991-02-21',
        'phone' => '+255711000001',
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

function makeRadiologyAppointment(string $patientId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Radiology',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Imaging referral',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);
}

function makeRadiologyAdmission(string $patientId): AdmissionModel
{
    return AdmissionModel::query()->create([
        'admission_number' => 'ADM'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward C',
        'bed' => 'C-12',
        'admitted_at' => now()->subHours(2)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Observation',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);
}

function ensureActiveRadiologyProcedureCatalogItem(array $overrides = []): ClinicalCatalogItemModel
{
    $attributes = array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'radiology_procedure',
        'code' => 'RAD-CXR-001',
        'name' => 'Chest X-Ray (PA)',
        'department_id' => null,
        'category' => 'xray',
        'unit' => null,
        'description' => 'Standard chest radiograph',
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

function radiologyOrderPayload(string $patientId, array $overrides = []): array
{
    return array_merge([
        'patientId' => $patientId,
        'orderedByUserId' => null,
        'orderedAt' => now()->toDateTimeString(),
        'procedureCode' => 'RAD-CXR-001',
        'modality' => 'xray',
        'studyDescription' => 'Chest X-Ray (PA)',
        'clinicalIndication' => 'Persistent cough',
        'scheduledFor' => now()->addHours(2)->toDateTimeString(),
    ], $overrides);
}

function grantRadiologyPermission(User $user, array $permissions): void
{
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }
}

function makeRadiologyUser(array $permissions = []): User
{
    $user = User::factory()->create();
    grantRadiologyPermission($user, array_merge([
        'radiology.orders.read',
        'radiology.orders.create',
        'radiology.orders.update',
        'radiology.orders.update-status',
    ], $permissions));

    return $user;
}

function advanceRadiologyOrderToCompleted($test, User $user, string $orderId, string $reportSummary)
{
    $test->actingAs($user)
        ->patchJson('/api/v1/radiology-orders/'.$orderId.'/status', [
            'status' => 'scheduled',
            'reason' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled');

    $test->actingAs($user)
        ->patchJson('/api/v1/radiology-orders/'.$orderId.'/status', [
            'status' => 'in_progress',
            'reason' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress');

    return $test->actingAs($user)
        ->patchJson('/api/v1/radiology-orders/'.$orderId.'/status', [
            'status' => 'completed',
            'reason' => null,
            'reportSummary' => $reportSummary,
        ]);
}

it('dispatches RadiologyOrderCompleted when the order reaches completed', function (): void {
    Event::fake([RadiologyOrderCompleted::class]);

    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id, [
            'orderedByUserId' => $user->id,
        ]))
        ->assertCreated()
        ->json('data');

    advanceRadiologyOrderToCompleted($this, $user, $created['id'], 'Clear.')
        ->assertOk();

    Event::assertDispatched(
        RadiologyOrderCompleted::class,
        fn (RadiologyOrderCompleted $event): bool => $event->radiologyOrderId === $created['id']
            && $event->patientId === $patient->id
            && $event->orderedByUserId === $user->id,
    );
});

it('does not dispatch RadiologyOrderCompleted for a non-completing transition', function (): void {
    Event::fake([RadiologyOrderCompleted::class]);

    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'].'/status', [
            'status' => 'scheduled',
            'reason' => null,
        ])
        ->assertOk();

    Event::assertNotDispatched(RadiologyOrderCompleted::class);
});

it('requires authentication for radiology order creation', function (): void {
    $patient = makeRadiologyPatient();

    $this->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertUnauthorized();
});

it('forbids radiology order list without read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/radiology-orders')
        ->assertForbidden();
});

it('can create radiology order and canonicalizes selected procedure from catalog', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();
    $appointment = makeRadiologyAppointment($patient->id);
    $admission = makeRadiologyAdmission($patient->id);
    $catalogItem = ensureActiveRadiologyProcedureCatalogItem();

    $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.appointmentId', $appointment->id)
        ->assertJsonPath('data.admissionId', $admission->id)
        ->assertJsonPath('data.radiologyProcedureCatalogItemId', $catalogItem->id)
        ->assertJsonPath('data.procedureCode', 'RAD-CXR-001')
        ->assertJsonPath('data.studyDescription', 'Chest X-Ray (PA)');
});

it('includes patient summary on radiology order list without patients.read permission', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient([
        'first_name' => 'Halima',
        'last_name' => 'Mushi',
        'patient_number' => 'PT-RAD-001',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertCreated();

    $this->actingAs($user)
        ->getJson('/api/v1/radiology-orders')
        ->assertOk()
        ->assertJsonPath('data.0.patientId', $patient->id)
        ->assertJsonPath('data.0.patient.firstName', 'Halima')
        ->assertJsonPath('data.0.patient.lastName', 'Mushi')
        ->assertJsonPath('data.0.patient.patientNumber', 'PT-RAD-001');
});

it('limits radiology order search to open worklist statuses when worklistScope=open', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient([
        'first_name' => 'Worklist',
        'last_name' => 'Scope',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'Patient declined imaging.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/radiology-orders?q=Worklist')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'cancelled');

    $this->actingAs($user)
        ->getJson('/api/v1/radiology-orders?q=Worklist&worklistScope=open')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('can search radiology orders by patient name', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient([
        'first_name' => 'Zawadi',
        'last_name' => 'Mwangi',
    ]);
    $otherPatient = makeRadiologyPatient([
        'first_name' => 'Other',
        'last_name' => 'Patient',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($otherPatient->id))
        ->assertCreated();

    $this->actingAs($user)
        ->getJson('/api/v1/radiology-orders?q=Zawadi')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.patientId', $patient->id);
});

it('flags insufficient recipe stock on radiology order show responses', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();
    $catalogItem = ensureActiveRadiologyProcedureCatalogItem([
        'code' => 'RAD-CT-ABD-CON',
        'name' => 'CT Abdomen with contrast',
        'category' => 'ct',
    ]);
    $contrast = clinicalRecipeStockInventoryItem([
        'item_code' => 'RAD-CONTRAST-IOHEXOL',
        'item_name' => 'Iohexol contrast media',
        'category' => 'radiology',
        'unit' => 'bottle',
        'current_stock' => 1,
    ]);

    clinicalRecipeStockRecipeLine($catalogItem->id, $contrast['id'], [
        'quantity_per_order' => 2,
        'unit' => 'bottle',
        'consumption_stage' => 'procedure_completion',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id, [
            'radiologyProcedureCatalogItemId' => $catalogItem->id,
            'procedureCode' => null,
            'studyDescription' => null,
            'modality' => 'ct',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/radiology-orders/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.stockPrecheck.status', 'insufficient')
        ->assertJsonPath('data.stockPrecheck.blocking', true)
        ->assertJsonPath('data.stockPrecheck.insufficientLineCount', 1)
        ->assertJsonPath('data.stockPrecheck.lines.0.enoughStock', false)
        ->assertJsonPath('data.stockPrecheck.lines.0.itemCode', 'RAD-CONTRAST-IOHEXOL');
});

it('creates radiology order from catalog item id without procedure code input', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();
    $catalogItem = ensureActiveRadiologyProcedureCatalogItem([
        'code' => 'RAD-CTH-003',
        'name' => 'CT Head (Non-contrast)',
        'category' => 'ct',
    ]);

    $payload = radiologyOrderPayload($patient->id, [
        'radiologyProcedureCatalogItemId' => $catalogItem->id,
        'procedureCode' => null,
        'studyDescription' => null,
        'modality' => 'ct',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('data.radiologyProcedureCatalogItemId', $catalogItem->id)
        ->assertJsonPath('data.procedureCode', 'RAD-CTH-003')
        ->assertJsonPath('data.studyDescription', 'CT Head (Non-contrast)');
});

it('rejects radiology order when procedure is not in active clinical catalog', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id, [
            'procedureCode' => 'RAD-UNKNOWN-999',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['procedureCode']);
});

it('rejects radiology order when appointment does not belong to patient', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();
    $otherPatient = makeRadiologyPatient([
        'phone' => '+255711000777',
        'first_name' => 'Other',
        'last_name' => 'Patient',
    ]);
    $appointment = makeRadiologyAppointment($otherPatient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id, [
            'appointmentId' => $appointment->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['appointmentId']);
});

it('updates radiology order and canonicalizes procedure fields when procedure code changes', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();
    $newProcedure = ensureActiveRadiologyProcedureCatalogItem([
        'code' => 'RAD-USA-011',
        'name' => 'Abdominal Ultrasound',
        'category' => 'ultrasound',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'], [
            'procedureCode' => 'RAD-USA-011',
            'modality' => 'ultrasound',
        ])
        ->assertOk()
        ->assertJsonPath('data.radiologyProcedureCatalogItemId', $newProcedure->id)
        ->assertJsonPath('data.procedureCode', 'RAD-USA-011')
        ->assertJsonPath('data.studyDescription', 'Abdominal Ultrasound')
        ->assertJsonPath('data.modality', 'ultrasound');
});

it('signs radiology draft orders before workflow begins', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.entryState', 'draft')
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders/'.$created['id'].'/sign')
        ->assertOk()
        ->assertJsonPath('data.entryState', 'active')
        ->assertJsonPath('data.signedByUserId', $user->id);

    advanceRadiologyOrderToCompleted(
        $this,
        $user,
        $created['id'],
        'Signed workflow now allowed.',
    )
        ->assertOk();
});

it('discards radiology drafts before signing and blocks deleting signed orders', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();

    $draft = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->deleteJson('/api/v1/radiology-orders/'.$draft['id'].'/draft')
        ->assertNoContent();

    $this->assertDatabaseMissing('radiology_orders', [
        'id' => $draft['id'],
    ]);

    $signed = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders/'.$signed['id'].'/sign')
        ->assertOk();

    $this->actingAs($user)
        ->deleteJson('/api/v1/radiology-orders/'.$signed['id'].'/draft')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order']);
});

it('blocks radiology status workflow while order remains draft', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'].'/status', [
            'status' => 'completed',
            'reportSummary' => 'Draft should not progress.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order']);
});

it('updates radiology status to completed and writes parity metadata in audit log', function (): void {
    $user = makeRadiologyUser(['radiology.orders.view-audit-logs']);
    $patient = makeRadiologyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    advanceRadiologyOrderToCompleted(
        $this,
        $user,
        $created['id'],
        'No acute cardiopulmonary abnormality.',
    )
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $row = RadiologyOrderModel::query()->findOrFail($created['id']);
    expect($row->completed_at)->not->toBeNull();

    $statusAudit = RadiologyOrderAuditLogModel::query()
        ->where('radiology_order_id', $created['id'])
        ->where('action', 'radiology-order.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();
    expect($statusAudit?->metadata ?? [])->toMatchArray([
        'completion_report_required' => true,
        'completion_report_provided' => true,
        'cancellation_reason_required' => false,
        'cancellation_reason_provided' => false,
    ]);
});

it('lists radiology audit logs when authorized', function (): void {
    $user = makeRadiologyUser(['radiology.orders.view-audit-logs']);
    $patient = makeRadiologyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/radiology-orders/'.$created['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'radiology-order.created');
});

it('exposes current care flags for abnormal radiology reports', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    advanceRadiologyOrderToCompleted(
        $this,
        $user,
        $created['id'],
        'Left lower lobe consolidation. Abnormal chest radiograph requiring clinician review.',
    )
        ->assertOk()
        ->assertJsonPath('data.currentCare.isCurrent', true)
        ->assertJsonPath('data.currentCare.requiresReview', true)
        ->assertJsonPath('data.currentCare.hasAbnormalReport', true)
        ->assertJsonPath('data.currentCare.priorityRank', 450)
        ->assertJsonPath('data.currentCare.workflowHint', 'Abnormal imaging report should be reviewed before follow-up.')
        ->assertJsonPath('data.currentCare.nextAction.key', 'review_report')
        ->assertJsonPath('data.currentCare.nextAction.label', 'Review abnormal report')
        ->assertJsonPath('data.currentCare.nextAction.emphasis', 'primary');
});

it('rejects skipping radiology scheduling before imaging starts', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'].'/status', [
            'status' => 'in_progress',
            'reason' => null,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('exports radiology audit logs csv when authorized', function (): void {
    $user = makeRadiologyUser(['radiology.orders.view-audit-logs']);
    $patient = makeRadiologyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $response = $this->actingAs($user)
        ->get('/api/v1/radiology-orders/'.$created['id'].'/audit-logs/export');

    $response->assertOk();
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    expect((string) $response->headers->get('content-type'))->toContain('text/csv');
    expect($response->streamedContent())->toContain('createdAt,action,actorType,actorId,changes,metadata');
});

it('stamps tenant and facility scope for radiology order creation under resolved scope', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();

    [$tenantId, $facilityId] = seedRadiologyPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-RAD',
        facilityName: 'Dar Imaging',
    );
    ensureActiveRadiologyProcedureCatalogItem([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
    ]);

    $created = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZH',
            'X-Facility-Code' => 'DAR-RAD',
        ])
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $row = RadiologyOrderModel::query()->findOrFail($created['id']);
    expect($row->tenant_id)->toBe($tenantId);
    expect($row->facility_id)->toBe($facilityId);
});

it('blocks radiology order creation in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('applies radiology cancel lifecycle action and records audit metadata', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders/'.$created['id'].'/lifecycle', [
            'action' => 'cancel',
            'reason' => 'Imaging deferred pending specialist review.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.lifecycleReasonCode', 'cancelled')
        ->assertJsonPath('data.statusReason', 'Imaging deferred pending specialist review.');

    $row = RadiologyOrderModel::query()->findOrFail($created['id']);
    expect($row->status)->toBe('cancelled');
    expect($row->lifecycle_reason_code)->toBe('cancelled');

    $audit = RadiologyOrderAuditLogModel::query()
        ->where('radiology_order_id', $created['id'])
        ->where('action', 'radiology-order.lifecycle.cancelled')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit?->metadata['lifecycle_action'] ?? null)->toBe('cancel');
});

it('requires reason for radiology lifecycle action', function (): void {
    $user = makeRadiologyUser();
    $patient = makeRadiologyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders', radiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/radiology-orders/'.$created['id'].'/lifecycle', [
            'action' => 'entered_in_error',
            'reason' => '',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

/**
 * @return array{0:string,1:string}
 */
function seedRadiologyPlatformScopeAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedRadiologyPlatformScopeFacility(
        tenantCode: $tenantCode,
        tenantName: $tenantName,
        countryCode: $countryCode,
        facilityCode: $facilityCode,
        facilityName: $facilityName,
    );

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'radiology_staff',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}

/**
 * @return array{0:string,1:string}
 */
function seedRadiologyPlatformScopeFacility(
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    $tenant = DB::table('tenants')->where('code', $tenantCode)->first();

    if ($tenant === null) {
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'code' => $tenantCode,
            'name' => $tenantName,
            'country_code' => $countryCode,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        $tenantId = (string) $tenant->id;
    }

    $facilityId = (string) Str::uuid();
    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => $facilityCode,
        'name' => $facilityName,
        'facility_type' => 'imaging',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}
