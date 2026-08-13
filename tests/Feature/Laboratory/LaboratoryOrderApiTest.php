<?php

use App\Models\User;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Jobs\GenerateAuditExportCsvJob;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderAuditLogModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Laboratory\Domain\Events\LaboratoryOrderCompleted;
use App\Modules\Platform\Infrastructure\Models\AuditExportJobModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use App\Models\Permission;
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    ensureLaboratoryActiveLabTestCatalogItem();
});

function makeLaboratoryPatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Amina',
        'middle_name' => null,
        'last_name' => 'Moshi',
        'gender' => 'female',
        'date_of_birth' => '1996-04-21',
        'phone' => '+255700000001',
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

function makeLaboratoryAppointment(string $patientId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Pre-lab consultation',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);
}

function makeLaboratoryAdmission(string $patientId): AdmissionModel
{
    return AdmissionModel::query()->create([
        'admission_number' => 'ADM'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward A',
        'bed' => 'A-02',
        'admitted_at' => now()->subHours(2)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Observation',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);
}

function ensureLaboratoryActiveLabTestCatalogItem(array $overrides = []): ClinicalCatalogItemModel
{
    $attributes = array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'lab_test',
        'code' => 'LOINC:57021-8',
        'name' => 'Complete Blood Count',
        'department_id' => null,
        'category' => 'hematology',
        'unit' => null,
        'description' => 'Default laboratory test fixture',
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

function laboratoryOrderPayload(string $patientId, array $overrides = []): array
{
    return array_merge([
        'patientId' => $patientId,
        'orderedByUserId' => null,
        'orderedAt' => now()->toDateTimeString(),
        'testCode' => 'LOINC:57021-8',
        'testName' => 'Complete Blood Count',
        'priority' => 'routine',
        'specimenType' => 'Blood',
        'clinicalNotes' => 'Suspected infection',
    ], $overrides);
}

function grantLaboratoryReadPermission(User $user): void
{
    Permission::query()->firstOrCreate(['name' => 'laboratory.orders.read']);
    $user->givePermissionTo('laboratory.orders.read');
}

function grantLaboratoryCreatePermission(User $user): void
{
    Permission::query()->firstOrCreate(['name' => 'laboratory.orders.create']);
    $user->givePermissionTo('laboratory.orders.create');
}

function grantLaboratoryUpdateStatusPermission(User $user): void
{
    Permission::query()->firstOrCreate(['name' => 'laboratory.orders.update-status']);
    $user->givePermissionTo('laboratory.orders.update-status');
}

function grantLaboratoryVerifyResultPermission(User $user): void
{
    Permission::query()->firstOrCreate(['name' => 'laboratory.orders.verify-result']);
    $user->givePermissionTo('laboratory.orders.verify-result');
}

function makeLaboratoryUser(): User
{
    $user = User::factory()->create();
    grantLaboratoryReadPermission($user);
    grantLaboratoryCreatePermission($user);
    grantLaboratoryUpdateStatusPermission($user);
    grantLaboratoryVerifyResultPermission($user);

    return $user;
}

function advanceLaboratoryOrderToCompleted($test, User $user, string $orderId, string $resultSummary)
{
    $test->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$orderId.'/status', [
            'status' => 'collected',
            'reason' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'collected');

    $test->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$orderId.'/status', [
            'status' => 'in_progress',
            'reason' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress');

    return $test->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$orderId.'/status', [
            'status' => 'completed',
            'reason' => null,
            'resultSummary' => $resultSummary,
        ]);
}

it('requires authentication for laboratory order creation', function (): void {
    $patient = makeLaboratoryPatient();

    $this->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->assertUnauthorized();
});

it('forbids laboratory order list without read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders')
        ->assertForbidden();
});

it('forbids laboratory order show without read permission', function (): void {
    $userWithRead = makeLaboratoryUser();
    $userWithoutRead = User::factory()->create();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($userWithRead)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/laboratory-orders/'.$created['id'])
        ->assertForbidden();
});

it('can create laboratory order for existing patient', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();
    $appointment = makeLaboratoryAppointment($patient->id);
    $admission = makeLaboratoryAdmission($patient->id);
    $catalogItem = ensureLaboratoryActiveLabTestCatalogItem();

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
            'orderedByUserId' => $user->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.appointmentId', $appointment->id)
        ->assertJsonPath('data.admissionId', $admission->id)
        ->assertJsonPath('data.labTestCatalogItemId', $catalogItem->id)
        ->assertJsonPath('data.status', 'ordered');
});

it('exposes live recipe stock precheck on laboratory order show responses', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();
    $catalogItem = ensureLaboratoryActiveLabTestCatalogItem();
    $reagent = clinicalRecipeStockInventoryItem([
        'item_code' => 'LAB-REAG-CBC-KIT',
        'item_name' => 'CBC reagent kit',
        'category' => 'laboratory',
        'unit' => 'kit',
        'current_stock' => 5,
    ]);

    clinicalRecipeStockRecipeLine($catalogItem->id, $reagent['id'], [
        'quantity_per_order' => 0.5,
        'unit' => 'kit',
        'waste_factor_percent' => 10,
        'consumption_stage' => 'processing',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.stockPrecheck.status', 'ready')
        ->assertJsonPath('data.stockPrecheck.blocking', false)
        ->assertJsonPath('data.stockPrecheck.lineCount', 1)
        ->assertJsonPath('data.stockPrecheck.lines.0.itemCode', 'LAB-REAG-CBC-KIT');
});

it('rejects laboratory order when test code is not in active clinical catalog', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'testCode' => 'LOINC:99999-9',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['testCode']);
});

it('creates laboratory order from active catalog item id and canonicalizes code/name', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();
    $catalogItem = ensureLaboratoryActiveLabTestCatalogItem([
        'code' => 'LOINC:77777-7',
        'name' => 'Serum Potassium',
        'category' => 'chemistry',
    ]);

    $payload = laboratoryOrderPayload($patient->id);
    unset($payload['testCode'], $payload['testName']);
    $payload['labTestCatalogItemId'] = $catalogItem->id;

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('data.labTestCatalogItemId', $catalogItem->id)
        ->assertJsonPath('data.testCode', 'LOINC:77777-7')
        ->assertJsonPath('data.testName', 'Serum Potassium');
});

it('rejects laboratory order for missing patient', function (): void {
    $user = makeLaboratoryUser();

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload((string) Str::uuid()))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['patientId']);
});

it('rejects laboratory order when appointment does not belong to patient', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();
    $otherPatient = makeLaboratoryPatient([
        'phone' => '+255788889999',
        'first_name' => 'Other',
        'last_name' => 'Patient',
    ]);
    $appointment = makeLaboratoryAppointment($otherPatient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'appointmentId' => $appointment->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['appointmentId']);
});

it('rejects laboratory order when admission does not belong to patient', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();
    $otherPatient = makeLaboratoryPatient([
        'phone' => '+255766667777',
        'first_name' => 'Third',
        'last_name' => 'Patient',
    ]);
    $admission = makeLaboratoryAdmission($otherPatient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'admissionId' => $admission->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['admissionId']);
});

it('fetches laboratory order by id', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.id', $created['id']);
});

it('returns 404 for unknown laboratory order id', function (): void {
    $user = makeLaboratoryUser();

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders/060afc03-2ce9-4b1d-a1c2-326d2722ce25')
        ->assertNotFound();
});

it('updates laboratory order fields', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'], [
            'priority' => 'urgent',
            'clinicalNotes' => 'Escalated due to fever spike',
        ])
        ->assertOk()
        ->assertJsonPath('data.priority', 'urgent')
        ->assertJsonPath('data.clinicalNotes', 'Escalated due to fever spike');
});

it('signs laboratory draft orders before workflow begins', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.entryState', 'draft')
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders/'.$created['id'].'/sign')
        ->assertOk()
        ->assertJsonPath('data.entryState', 'active')
        ->assertJsonPath('data.signedByUserId', $user->id);

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders/'.$created['id'].'/sign')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order']);
});

it('discards laboratory drafts before signing and blocks deleting signed orders', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $draft = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->deleteJson('/api/v1/laboratory-orders/'.$draft['id'].'/draft')
        ->assertNoContent();

    $this->assertDatabaseMissing('laboratory_orders', [
        'id' => $draft['id'],
    ]);

    $signed = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders/'.$signed['id'].'/sign')
        ->assertOk();

    $this->actingAs($user)
        ->deleteJson('/api/v1/laboratory-orders/'.$signed['id'].'/draft')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order']);
});

it('blocks laboratory status workflow while order remains draft', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/status', [
            'status' => 'completed',
            'resultSummary' => 'Draft should not progress.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order']);
});

it('rejects empty laboratory order patch payload', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'], [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payload']);
});

it('forbids laboratory order status update without workflow permission', function (): void {
    $user = User::factory()->create();
    grantLaboratoryReadPermission($user);
    grantLaboratoryCreatePermission($user);
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/status', [
            'status' => 'completed',
            'resultSummary' => 'No significant abnormalities detected',
        ])
        ->assertForbidden();
});

it('updates laboratory order status to completed and sets result timestamp', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    advanceLaboratoryOrderToCompleted(
        $this,
        $user,
        $created['id'],
        'No significant abnormalities detected',
    )
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.resultSummary', 'No significant abnormalities detected');

    $record = LaboratoryOrderModel::query()->find($created['id']);
    expect($record?->resulted_at)->not->toBeNull();
});

it('dispatches LaboratoryOrderCompleted when the order reaches completed', function (): void {
    Event::fake([LaboratoryOrderCompleted::class]);

    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'orderedByUserId' => $user->id,
        ]))
        ->json('data');

    advanceLaboratoryOrderToCompleted($this, $user, $created['id'], 'Normal')
        ->assertOk();

    Event::assertDispatched(
        LaboratoryOrderCompleted::class,
        fn (LaboratoryOrderCompleted $event): bool => $event->laboratoryOrderId === $created['id']
            && $event->patientId === $patient->id
            && $event->orderedByUserId === $user->id,
    );
});

it('does not dispatch LaboratoryOrderCompleted for a non-completing transition', function (): void {
    Event::fake([LaboratoryOrderCompleted::class]);

    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/status', [
            'status' => 'collected',
            'reason' => null,
        ])
        ->assertOk();

    Event::assertNotDispatched(LaboratoryOrderCompleted::class);
});

it('forbids laboratory order verification without verification permission', function (): void {
    $user = User::factory()->create();
    grantLaboratoryReadPermission($user);
    grantLaboratoryCreatePermission($user);
    grantLaboratoryUpdateStatusPermission($user);
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    advanceLaboratoryOrderToCompleted(
        $this,
        $user,
        $created['id'],
        'CBC within expected range',
    )
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Ready for release',
        ])
        ->assertForbidden();
});

it('verifies completed laboratory order result and stores verification metadata', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    advanceLaboratoryOrderToCompleted(
        $this,
        $user,
        $created['id'],
        'CBC within expected range',
    )
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Reviewed and released by technologist.',
        ])
        ->assertOk()
        ->assertJsonPath('data.verifiedByUserId', $user->id)
        ->assertJsonPath('data.verificationNote', 'Reviewed and released by technologist.');

    $record = LaboratoryOrderModel::query()->find($created['id']);
    expect($record?->verified_at)->not->toBeNull();
    expect($record?->verified_by_user_id)->toBe($user->id);
    expect($record?->verification_note)->toBe('Reviewed and released by technologist.');

    $verificationAuditLog = LaboratoryOrderAuditLogModel::query()
        ->where('laboratory_order_id', $created['id'])
        ->where('action', 'laboratory-order.result.verified')
        ->latest('created_at')
        ->first();

    expect($verificationAuditLog)->not->toBeNull();
    expect($verificationAuditLog?->metadata ?? [])->toMatchArray([
        'critical_result' => false,
        'verification_note_required' => false,
        'verification_note_provided' => true,
    ]);
});

it('rejects repeat verification for an already verified laboratory result', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    advanceLaboratoryOrderToCompleted(
        $this,
        $user,
        $created['id'],
        'CBC within expected range',
    )
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'First verification.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Second verification should fail.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['verification']);
});

it('rejects laboratory result verification when order is not completed', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Should fail before completion.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['verification']);
});

it('requires verification note for critical laboratory results', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    advanceLaboratoryOrderToCompleted(
        $this,
        $user,
        $created['id'],
        "Result Flag: critical\nMeasured Result: 2.1 mmol/L",
    )
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/verify', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['verification']);
});

it('enforces reason on cancelled laboratory order status', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/status', [
            'status' => 'cancelled',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('writes laboratory order audit logs for create update and status change', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/laboratory-orders/'.$created['id'], [
        'priority' => 'urgent',
        'clinicalNotes' => 'audit update check',
    ])->assertOk();

    $this->actingAs($user)->postJson('/api/v1/laboratory-orders/'.$created['id'].'/sign')
        ->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/status', [
        'status' => 'cancelled',
        'reason' => 'sample compromised',
    ])->assertOk();

    $logs = LaboratoryOrderAuditLogModel::query()
        ->where('laboratory_order_id', $created['id'])
        ->orderBy('created_at')
        ->get();

    expect($logs)->toHaveCount(4);
    expect($logs->pluck('action')->all())->toContain(
        'laboratory-order.created',
        'laboratory-order.updated',
        'laboratory-order.signed',
        'laboratory-order.status.updated',
    );
    expect($logs->first()->actor_id)->toBe($user->id);
});

it('lists laboratory order audit logs when authorized', function (): void {
    $user = makeLaboratoryUser();
    $user->givePermissionTo('laboratory-orders.view-audit-logs');
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/laboratory-orders/'.$created['id'], [
        'priority' => 'stat',
    ])->assertOk();

    $this->actingAs($user)->postJson('/api/v1/laboratory-orders/'.$created['id'].'/sign')
        ->assertOk();

    advanceLaboratoryOrderToCompleted(
        $this,
        $user,
        $created['id'],
        'audit pagination check',
    )->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs?perPage=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 6)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('data.0.action', 'laboratory-order.status.updated')
        ->assertJsonPath('data.1.action', 'laboratory-order.status.updated');
});

it('filters laboratory order audit logs by action text actor type and actor id', function (): void {
    $user = makeLaboratoryUser();
    $user->givePermissionTo('laboratory-orders.view-audit-logs');
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/laboratory-orders/'.$created['id'], [
        'priority' => 'urgent',
        'clinicalNotes' => 'audit filter update',
    ])->assertOk();

    $this->actingAs($user)->postJson('/api/v1/laboratory-orders/'.$created['id'].'/sign')
        ->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/status', [
        'status' => 'cancelled',
        'reason' => 'audit filter check',
    ])->assertOk();

    LaboratoryOrderAuditLogModel::query()->create([
        'laboratory_order_id' => $created['id'],
        'action' => 'laboratory-order.system.reconciled',
        'actor_id' => null,
        'changes' => [],
        'metadata' => ['source' => 'system-job'],
        'created_at' => now()->addSecond(),
    ]);

    $this->actingAs($user)
        ->getJson(
            '/api/v1/laboratory-orders/'.$created['id']
                .'/audit-logs?actorType=user&actorId='.$user->id
                .'&action=laboratory-order.status.updated&q=STATUS',
        )
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'laboratory-order.status.updated')
        ->assertJsonPath('data.0.actorId', $user->id);

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs?actorType=system&q=RECONCILED')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'laboratory-order.system.reconciled')
        ->assertJsonPath('data.0.actorId', null);
});

it('exports laboratory order audit logs as csv when authorized and applies filters', function (): void {
    $user = makeLaboratoryUser();
    $user->givePermissionTo('laboratory-orders.view-audit-logs');
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/laboratory-orders/'.$created['id'], [
        'priority' => 'urgent',
        'clinicalNotes' => 'audit export update',
    ])->assertOk();

    $this->actingAs($user)->postJson('/api/v1/laboratory-orders/'.$created['id'].'/sign')
        ->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/status', [
        'status' => 'cancelled',
        'reason' => 'audit export check',
    ])->assertOk();

    LaboratoryOrderAuditLogModel::query()->create([
        'laboratory_order_id' => $created['id'],
        'action' => 'laboratory-order.system.reconciled',
        'actor_id' => null,
        'changes' => [],
        'metadata' => ['source' => 'system-job'],
        'created_at' => now()->addSecond(),
    ]);

    $response = $this->actingAs($user)
        ->get('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs/export?actorType=system&q=RECONCILED');

    $response->assertOk();
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    $response->assertHeader('X-Export-System-Name', 'Afyanova AHS');
    $response->assertHeader('X-Export-System-Slug', 'afyanova_ahs');
    expect((string) $response->headers->get('content-type'))->toContain('text/csv');
    expect((string) $response->headers->get('content-disposition'))->toContain('afyanova_ahs_laboratory_audit_');
    expect($response->streamedContent())->toContain('createdAt,action,actorType,actorId,changes,metadata');
    expect($response->streamedContent())->toContain('laboratory-order.system.reconciled');
});

it('creates laboratory order audit log csv export job when authorized', function (): void {
    Queue::fake();

    $user = makeLaboratoryUser();
    $user->givePermissionTo('laboratory-orders.view-audit-logs');
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs/export-jobs', [
            'actorType' => 'system',
            'q' => 'reconciled',
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.schemaVersion', 'audit-log-csv.v1')
        ->assertJsonPath('data.downloadUrl', null);

    $jobId = (string) $response->json('data.id');
    $job = AuditExportJobModel::query()->findOrFail($jobId);

    expect($job->module)->toBe(GenerateAuditExportCsvJob::MODULE_LABORATORY);
    expect($job->target_resource_id)->toBe($created['id']);
    expect($job->created_by_user_id)->toBe($user->id);
    expect($job->status)->toBe('queued');
    expect($job->filters)->toMatchArray([
        'q' => 'reconciled',
        'action' => null,
        'actorType' => 'system',
        'actorId' => null,
        'from' => null,
        'to' => null,
    ]);

    Queue::assertPushed(GenerateAuditExportCsvJob::class, 1);
});

it('shows laboratory order audit log csv export job status for creator', function (): void {
    $user = makeLaboratoryUser();
    $user->givePermissionTo('laboratory-orders.view-audit-logs');
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $job = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_LABORATORY,
        'target_resource_id' => $created['id'],
        'status' => 'completed',
        'row_count' => 4,
        'file_path' => 'audit-exports/test-laboratory-status.csv',
        'file_name' => 'laboratory_status.csv',
        'created_by_user_id' => $user->id,
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs/export-jobs/'.$job->id)
        ->assertOk()
        ->assertJsonPath('data.id', (string) $job->id)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.rowCount', 4)
        ->assertJsonPath('data.schemaVersion', 'audit-log-csv.v1')
        ->assertJsonPath(
            'data.downloadUrl',
            '/api/v1/laboratory-orders/'.$created['id'].'/audit-logs/export-jobs/'.$job->id.'/download',
        );
});

it('downloads completed laboratory order audit log csv export job', function (): void {
    Storage::fake('local');

    $user = makeLaboratoryUser();
    $user->givePermissionTo('laboratory-orders.view-audit-logs');
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $filePath = 'audit-exports/test-laboratory-download.csv';
    Storage::disk('local')->put($filePath, "createdAt,action,actorType,actorId,changes,metadata\n");

    $job = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_LABORATORY,
        'target_resource_id' => $created['id'],
        'status' => 'completed',
        'row_count' => 1,
        'file_path' => $filePath,
        'file_name' => 'laboratory_download.csv',
        'created_by_user_id' => $user->id,
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs/export-jobs/'.$job->id.'/download');

    $response->assertOk();
    $response->assertDownload('laboratory_download.csv');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    $response->assertHeader('X-Export-System-Name', 'Afyanova AHS');
    $response->assertHeader('X-Export-System-Slug', 'afyanova_ahs');
    expect((string) $response->headers->get('content-type'))->toContain('text/csv');
});

it('returns 409 when laboratory order audit log csv export job is not ready', function (): void {
    $user = makeLaboratoryUser();
    $user->givePermissionTo('laboratory-orders.view-audit-logs');
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $job = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_LABORATORY,
        'target_resource_id' => $created['id'],
        'status' => 'queued',
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs/export-jobs/'.$job->id.'/download')
        ->assertStatus(409)
        ->assertJsonPath('code', 'EXPORT_JOB_NOT_READY');
});

it('lists laboratory order audit log csv export jobs for creator only', function (): void {
    $user = makeLaboratoryUser();
    $user->givePermissionTo('laboratory-orders.view-audit-logs');
    $otherUser = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $jobOne = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_LABORATORY,
        'target_resource_id' => $created['id'],
        'status' => 'failed',
        'filters' => ['actorType' => 'system'],
        'created_by_user_id' => $user->id,
        'error_message' => 'test failure',
    ]);
    $jobTwo = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_LABORATORY,
        'target_resource_id' => $created['id'],
        'status' => 'completed',
        'row_count' => 2,
        'file_path' => 'audit-exports/test-laboratory-history.csv',
        'file_name' => 'laboratory_history.csv',
        'created_by_user_id' => $user->id,
        'completed_at' => now(),
    ]);
    AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_LABORATORY,
        'target_resource_id' => $created['id'],
        'status' => 'queued',
        'created_by_user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs/export-jobs?perPage=10');

    $response->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.perPage', 10);

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain((string) $jobOne->id, (string) $jobTwo->id);
});

it('retries laboratory order audit log csv export job when authorized', function (): void {
    Queue::fake();

    $user = makeLaboratoryUser();
    $user->givePermissionTo('laboratory-orders.view-audit-logs');
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $sourceJob = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_LABORATORY,
        'target_resource_id' => $created['id'],
        'status' => 'failed',
        'filters' => [
            'q' => 'reconciled',
            'action' => null,
            'actorType' => 'system',
            'actorId' => null,
            'from' => null,
            'to' => null,
        ],
        'created_by_user_id' => $user->id,
        'error_message' => 'test failure',
        'failed_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs/export-jobs/'.$sourceJob->id.'/retry');

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.downloadUrl', null);

    $retryJobId = (string) $response->json('data.id');
    expect($retryJobId)->not()->toBe((string) $sourceJob->id);

    $retryJob = AuditExportJobModel::query()->findOrFail($retryJobId);
    expect($retryJob->module)->toBe(GenerateAuditExportCsvJob::MODULE_LABORATORY);
    expect($retryJob->target_resource_id)->toBe($created['id']);
    expect($retryJob->created_by_user_id)->toBe($user->id);
    expect($retryJob->filters)->toMatchArray($sourceJob->filters ?? []);

    Queue::assertPushed(GenerateAuditExportCsvJob::class, 1);
});

it('forbids laboratory order audit log access without permission', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('forbids laboratory order audit log csv export job retry without permission', function (): void {
    Queue::fake();

    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $sourceJob = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_LABORATORY,
        'target_resource_id' => $created['id'],
        'status' => 'failed',
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs/export-jobs/'.$sourceJob->id.'/retry')
        ->assertForbidden();
});

it('forbids laboratory order audit log csv export job create without permission', function (): void {
    Queue::fake();

    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs/export-jobs')
        ->assertForbidden();
});

it('forbids laboratory order audit log csv export access without permission', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->get('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs/export')
        ->assertForbidden();
});

it('forbids laboratory order audit logs when gate override denies', function (): void {
    Gate::define('laboratory-orders.view-audit-logs', static fn (): bool => false);

    $user = makeLaboratoryUser();
    $user->givePermissionTo('laboratory-orders.view-audit-logs');
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('returns 404 for laboratory order audit logs of unknown id', function (): void {
    $user = makeLaboratoryUser();
    $user->givePermissionTo('laboratory-orders.view-audit-logs');

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders/060afc03-2ce9-4b1d-a1c2-326d2722ce25/audit-logs')
        ->assertNotFound();
});

it('lists and filters laboratory orders', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB20260225AAAAAA',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'clinical_notes' => null,
        'result_summary' => null,
        'resulted_at' => null,
        'status' => 'ordered',
        'status_reason' => null,
    ]);

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB20260225BBBBBB',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHours(5)->toDateTimeString(),
        'test_code' => 'LOINC:24356-8',
        'test_name' => 'Urinalysis',
        'priority' => 'urgent',
        'specimen_type' => 'Urine',
        'clinical_notes' => null,
        'result_summary' => 'Mild proteinuria',
        'resulted_at' => now()->subHours(4)->toDateTimeString(),
        'status' => 'completed',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders?q=Complete&status=ordered&priority=routine')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.testName', 'Complete Blood Count')
        ->assertJsonPath('data.0.status', 'ordered')
        ->assertJsonPath('data.0.priority', 'routine');
});

it('exposes current care flags for critical laboratory results', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    advanceLaboratoryOrderToCompleted(
        $this,
        $user,
        $created['id'],
        "Result Flag: critical\nMeasured Result: 2.1 mmol/L",
    )
        ->assertOk()
        ->assertJsonPath('data.currentCare.isCurrent', true)
        ->assertJsonPath('data.currentCare.requiresReview', true)
        ->assertJsonPath('data.currentCare.hasCriticalResult', true)
        ->assertJsonPath('data.currentCare.priorityRank', 500)
        ->assertJsonPath('data.currentCare.workflowHint', 'Critical laboratory result needs immediate review.')
        ->assertJsonPath('data.currentCare.nextAction.key', 'review_result')
        ->assertJsonPath('data.currentCare.nextAction.label', 'Review critical result')
        ->assertJsonPath('data.currentCare.nextAction.emphasis', 'warning');
});

it('rejects skipping laboratory specimen collection before processing starts', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/status', [
            'status' => 'in_progress',
            'reason' => null,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('stamps laboratory order tenant and facility scope when created under resolved platform scope', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    [$tenantId, $facilityId] = seedLaboratoryPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-LAB',
        facilityName: 'Dar Laboratory',
    );
    ensureLaboratoryActiveLabTestCatalogItem([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
    ]);

    $created = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZH',
            'X-Facility-Code' => 'DAR-LAB',
        ])
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $row = LaboratoryOrderModel::query()->findOrFail($created['id']);

    expect($row->tenant_id)->toBe($tenantId);
    expect($row->facility_id)->toBe($facilityId);
});

it('filters laboratory order reads by facility scope when platform multi facility scoping is enabled', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', true);

    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    [$tenantId, $facilityId] = seedLaboratoryPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-LAB',
        facilityName: 'Nairobi Lab',
    );

    [, $otherFacilityId] = seedLaboratoryPlatformScopeFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-LAB',
        facilityName: 'Mombasa Lab',
    );

    $visible = LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB20260225SCOPL1',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHour()->toDateTimeString(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Scoped Visible CBC',
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'clinical_notes' => null,
        'result_summary' => null,
        'resulted_at' => null,
        'status' => 'ordered',
        'status_reason' => null,
    ]);

    $hidden = LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB20260225SCOPL2',
        'tenant_id' => $tenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'test_code' => 'LOINC:24356-8',
        'test_name' => 'Scoped Hidden Urinalysis',
        'priority' => 'urgent',
        'specimen_type' => 'Urine',
        'clinical_notes' => null,
        'result_summary' => null,
        'resulted_at' => null,
        'status' => 'ordered',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-LAB',
        ])
        ->getJson('/api/v1/laboratory-orders')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.testName', 'Scoped Visible CBC');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-LAB',
        ])
        ->getJson('/api/v1/laboratory-orders/'.$hidden->id)
        ->assertNotFound();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-LAB',
        ])
        ->patchJson('/api/v1/laboratory-orders/'.$hidden->id, [
            'priority' => 'stat',
        ])
        ->assertNotFound();
});

it('filters laboratory order reads by facility scope when enabled via feature flag override', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', false);

    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    [$tenantId, $facilityId] = seedLaboratoryPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-LAB',
        facilityName: 'Nairobi Lab',
    );

    [, $otherFacilityId] = seedLaboratoryPlatformScopeFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-LAB',
        facilityName: 'Mombasa Lab',
    );

    DB::table('feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_name' => 'platform.multi_facility_scoping',
        'scope_type' => 'country',
        'scope_key' => 'KE',
        'enabled' => true,
        'reason' => 'enable scoping for Kenya lab rollout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $visible = LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB20260225SCOPL3',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHour()->toDateTimeString(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Override Visible CBC',
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'clinical_notes' => null,
        'result_summary' => null,
        'resulted_at' => null,
        'status' => 'ordered',
        'status_reason' => null,
    ]);

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB20260225SCOPL4',
        'tenant_id' => $tenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'test_code' => 'LOINC:24356-8',
        'test_name' => 'Override Hidden Urinalysis',
        'priority' => 'urgent',
        'specimen_type' => 'Urine',
        'clinical_notes' => null,
        'result_summary' => null,
        'resulted_at' => null,
        'status' => 'ordered',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-LAB',
        ])
        ->getJson('/api/v1/laboratory-orders')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.testName', 'Override Visible CBC');
});

it('blocks laboratory order creation in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks laboratory order update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $order = LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB20260225GUARDL1',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->toDateTimeString(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Guard Update Target',
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'clinical_notes' => null,
        'result_summary' => null,
        'resulted_at' => null,
        'status' => 'ordered',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id, [
            'priority' => 'urgent',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks laboratory order status update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $order = LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB20260225GUARDL2',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->toDateTimeString(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Guard Status Target',
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'clinical_notes' => null,
        'result_summary' => null,
        'resulted_at' => null,
        'status' => 'ordered',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/status', [
            'status' => 'completed',
            'resultSummary' => 'done',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('applies laboratory entered-in-error lifecycle action and records audit metadata', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/laboratory-orders/'.$created['id'].'/lifecycle', [
            'action' => 'entered_in_error',
            'reason' => 'Wrong patient selected during order entry.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.lifecycleReasonCode', 'entered_in_error')
        ->assertJsonPath('data.statusReason', 'Wrong patient selected during order entry.');

    $row = LaboratoryOrderModel::query()->findOrFail($created['id']);
    expect($row->status)->toBe('cancelled');
    expect($row->lifecycle_reason_code)->toBe('entered_in_error');
    expect($row->entered_in_error_at)->not->toBeNull();

    $audit = LaboratoryOrderAuditLogModel::query()
        ->where('laboratory_order_id', $created['id'])
        ->where('action', 'laboratory-order.lifecycle.entered-in-error')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit?->metadata['lifecycle_action'] ?? null)->toBe('entered_in_error');
});

it('forbids laboratory lifecycle action without create permission', function (): void {
    $author = makeLaboratoryUser();
    $unauthorized = User::factory()->create();
    $patient = makeLaboratoryPatient();

    $created = $this->actingAs($author)
        ->postJson('/api/v1/laboratory-orders', laboratoryOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($unauthorized)
        ->postJson('/api/v1/laboratory-orders/'.$created['id'].'/lifecycle', [
            'action' => 'cancel',
            'reason' => 'No longer clinically required.',
        ])
        ->assertForbidden();
});

/**
 * @return array{0:string,1:string}
 */
function seedLaboratoryPlatformScopeAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedLaboratoryPlatformScopeFacility(
        tenantCode: $tenantCode,
        tenantName: $tenantName,
        countryCode: $countryCode,
        facilityCode: $facilityCode,
        facilityName: $facilityName,
    );

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'lab_staff',
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
function seedLaboratoryPlatformScopeFacility(
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
        'facility_type' => 'laboratory',
        'timezone' => 'Africa/Nairobi',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}

it('detects same-encounter laboratory duplicates by encounter id', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();
    $catalogItem = ensureLaboratoryActiveLabTestCatalogItem();

    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'status' => 'in_progress',
        'opened_at' => now(),
    ]);

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'appointment_id' => null,
        'admission_id' => null,
        'lab_test_catalog_item_id' => $catalogItem->id,
        'test_code' => $catalogItem->code,
        'test_name' => $catalogItem->name,
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'clinical_notes' => 'Existing encounter order',
        'ordered_at' => now()->subHour(),
        'status' => 'ordered',
        'entry_state' => 'active',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders/duplicate-check?'.http_build_query([
            'patientId' => $patient->id,
            'encounterId' => $encounter->id,
            'labTestCatalogItemId' => $catalogItem->id,
        ]))
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.sameEncounterDuplicates')
        ->assertJsonPath('data.sameEncounterDuplicates.0.encounterId', $encounter->id);
});

it('filters laboratory orders by encounter id', function (): void {
    $user = makeLaboratoryUser();
    $patient = makeLaboratoryPatient();
    $catalogItem = ensureLaboratoryActiveLabTestCatalogItem();

    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'status' => 'in_progress',
        'opened_at' => now(),
    ]);

    $otherEncounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'status' => 'in_progress',
        'opened_at' => now(),
    ]);

    foreach ([$encounter->id, $otherEncounter->id] as $encounterId) {
        LaboratoryOrderModel::query()->create([
            'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
            'patient_id' => $patient->id,
            'encounter_id' => $encounterId,
            'lab_test_catalog_item_id' => $catalogItem->id,
            'test_code' => $catalogItem->code,
            'test_name' => $catalogItem->name,
            'priority' => 'routine',
            'ordered_at' => now(),
            'status' => 'ordered',
            'entry_state' => 'active',
        ]);
    }

    $this->actingAs($user)
        ->getJson('/api/v1/laboratory-orders?'.http_build_query([
            'patientId' => $patient->id,
            'encounterId' => $encounter->id,
            'perPage' => 10,
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.encounterId', $encounter->id);
});
