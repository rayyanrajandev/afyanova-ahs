<?php

/**
 * Radiology result verification — the release step imaging never had.
 *
 * Before this, `completed` was the end of the radiology lifecycle: a report was
 * on the patient's chart the instant the radiographer saved it, with no second
 * pair of eyes and no way to tell a draft from a released study. These tests pin
 * the same rules the laboratory equivalent enforces, in the same order, and —
 * critically — that every refusal happens before anything is written.
 *
 * Permissions are the ones the routes declare: `imaging.order`,
 * `imaging.perform`, `imaging.result.verify`, `radiology.orders.read`.
 */

use App\Models\Permission;
use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderAuditLogModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedVerifyRadiologyCatalogItem();
});

function seedVerifyRadiologyCatalogItem(): ClinicalCatalogItemModel
{
    return ClinicalCatalogItemModel::query()->firstOrCreate(
        [
            'tenant_id' => null,
            'facility_id' => null,
            'catalog_type' => 'radiology_procedure',
            'code' => 'RAD-CXR-001',
        ],
        [
            'name' => 'Chest X-Ray (PA)',
            'department_id' => null,
            'category' => 'xray',
            'unit' => null,
            'description' => 'Verification test fixture',
            'metadata' => null,
            'status' => 'active',
            'status_reason' => null,
        ],
    );
}

function makeVerifyRadiologyPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'middle_name' => null,
        'last_name' => 'Kweka',
        'gender' => 'female',
        'date_of_birth' => '1991-02-21',
        'phone' => '+255711000002',
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

/**
 * @return array<string, mixed>
 */
function verifyRadiologyOrderPayload(string $patientId): array
{
    return [
        'patientId' => $patientId,
        'orderedByUserId' => null,
        'orderedAt' => now()->toDateTimeString(),
        'procedureCode' => 'RAD-CXR-001',
        'modality' => 'xray',
        'studyDescription' => 'Chest X-Ray (PA)',
        'clinicalIndication' => 'Persistent cough',
        'scheduledFor' => now()->addHours(2)->toDateTimeString(),
    ];
}

/**
 * @param  array<int, string>  $abilities
 */
function makeVerifyRadiologyUser(array $abilities): User
{
    $user = User::factory()->create();

    foreach ($abilities as $ability) {
        Permission::query()->firstOrCreate(['name' => $ability]);
        $user->givePermissionTo($ability);
    }

    return $user;
}

function walkRadiologyOrderToCompleted($test, User $user, string $orderId, string $reportSummary): void
{
    foreach (['scheduled', 'in_progress'] as $status) {
        $test->actingAs($user)
            ->patchJson('/api/v1/radiology-orders/'.$orderId.'/status', [
                'status' => $status,
                'reason' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', $status);
    }

    $test->actingAs($user)
        ->patchJson('/api/v1/radiology-orders/'.$orderId.'/status', [
            'status' => 'completed',
            'reason' => null,
            'reportSummary' => $reportSummary,
        ])
        ->assertOk();
}

function radiographerAndReporter(): array
{
    return [
        makeVerifyRadiologyUser(['imaging.order', 'radiology.orders.read', 'imaging.perform']),
        makeVerifyRadiologyUser(['radiology.orders.read', 'imaging.result.verify']),
    ];
}

it('leaves a completed report unverified until it is released', function (): void {
    [$radiographer] = radiographerAndReporter();
    $patient = makeVerifyRadiologyPatient();

    $created = $this->actingAs($radiographer)
        ->postJson('/api/v1/radiology-orders', verifyRadiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    walkRadiologyOrderToCompleted($this, $radiographer, $created['id'], 'No acute findings.');

    // The distinction the schema previously could not express.
    $record = RadiologyOrderModel::query()->find($created['id']);
    expect($record?->status)->toBe('completed');
    expect($record?->verified_at)->toBeNull();
});

it('releases a completed report when a second reporter verifies it', function (): void {
    [$radiographer, $reporter] = radiographerAndReporter();
    $patient = makeVerifyRadiologyPatient();

    $created = $this->actingAs($radiographer)
        ->postJson('/api/v1/radiology-orders', verifyRadiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    walkRadiologyOrderToCompleted($this, $radiographer, $created['id'], 'No acute findings.');

    $this->actingAs($reporter)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Reviewed and released.',
        ])
        ->assertOk()
        ->assertJsonPath('data.verifiedByUserId', $reporter->id)
        ->assertJsonPath('data.verificationNote', 'Reviewed and released.');

    $record = RadiologyOrderModel::query()->find($created['id']);
    expect($record?->verified_at)->not->toBeNull();
    expect($record?->verified_by_user_id)->toBe($reporter->id);

    expect(
        RadiologyOrderAuditLogModel::query()
            ->where('radiology_order_id', $created['id'])
            ->where('action', 'radiology-order.result.verified')
            ->count()
    )->toBe(1);
});

it('refuses self-verification before writing anything', function (): void {
    $orderer = makeVerifyRadiologyUser([
        'imaging.order',
        'radiology.orders.read',
        'imaging.perform',
        'imaging.result.verify',
    ]);
    $patient = makeVerifyRadiologyPatient();

    $created = $this->actingAs($orderer)
        ->postJson('/api/v1/radiology-orders', verifyRadiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    walkRadiologyOrderToCompleted($this, $orderer, $created['id'], 'No acute findings.');

    $this->actingAs($orderer)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Verifying my own study.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['verification']);

    $record = RadiologyOrderModel::query()->find($created['id']);
    expect($record?->verified_at)->toBeNull();
    expect($record?->verified_by_user_id)->toBeNull();
    expect($record?->verification_note)->toBeNull();

    expect(
        RadiologyOrderAuditLogModel::query()
            ->where('radiology_order_id', $created['id'])
            ->where('action', 'radiology-order.result.verified')
            ->count()
    )->toBe(0);
});

it('refuses verification of a study that was never completed', function (): void {
    [$radiographer, $reporter] = radiographerAndReporter();
    $patient = makeVerifyRadiologyPatient();

    $created = $this->actingAs($radiographer)
        ->postJson('/api/v1/radiology-orders', verifyRadiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($reporter)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Too early.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['verification']);

    expect(RadiologyOrderModel::query()->find($created['id'])?->verified_at)->toBeNull();
});

it('refuses a repeat verification', function (): void {
    [$radiographer, $reporter] = radiographerAndReporter();
    $patient = makeVerifyRadiologyPatient();

    $created = $this->actingAs($radiographer)
        ->postJson('/api/v1/radiology-orders', verifyRadiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    walkRadiologyOrderToCompleted($this, $radiographer, $created['id'], 'No acute findings.');

    $this->actingAs($reporter)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'].'/verify', ['verificationNote' => 'First.'])
        ->assertOk();

    $this->actingAs($reporter)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'].'/verify', ['verificationNote' => 'Second.'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['verification']);

    expect(
        RadiologyOrderAuditLogModel::query()
            ->where('radiology_order_id', $created['id'])
            ->where('action', 'radiology-order.result.verified')
            ->count()
    )->toBe(1);
});

it('requires a verification note for critical findings', function (): void {
    [$radiographer, $reporter] = radiographerAndReporter();
    $patient = makeVerifyRadiologyPatient();

    $created = $this->actingAs($radiographer)
        ->postJson('/api/v1/radiology-orders', verifyRadiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    walkRadiologyOrderToCompleted(
        $this,
        $radiographer,
        $created['id'],
        "Result Flag: critical\nTension pneumothorax, left hemithorax.",
    );

    $this->actingAs($reporter)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'].'/verify', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['verification']);

    expect(RadiologyOrderModel::query()->find($created['id'])?->verified_at)->toBeNull();

    // The same reporter succeeds once the finding is documented.
    $this->actingAs($reporter)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Called ED registrar 14:20, read-back confirmed.',
        ])
        ->assertOk();

    expect(RadiologyOrderModel::query()->find($created['id'])?->verified_at)->not->toBeNull();
});

it('forbids verification without the verify permission', function (): void {
    [$radiographer] = radiographerAndReporter();
    // A bench radiographer: may perform the study, may not release the report.
    $benchOnly = makeVerifyRadiologyUser(['radiology.orders.read', 'imaging.perform']);
    $patient = makeVerifyRadiologyPatient();

    $created = $this->actingAs($radiographer)
        ->postJson('/api/v1/radiology-orders', verifyRadiologyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    walkRadiologyOrderToCompleted($this, $radiographer, $created['id'], 'No acute findings.');

    $this->actingAs($benchOnly)
        ->patchJson('/api/v1/radiology-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Not my call to make.',
        ])
        ->assertForbidden();

    expect(RadiologyOrderModel::query()->find($created['id'])?->verified_at)->toBeNull();
});
