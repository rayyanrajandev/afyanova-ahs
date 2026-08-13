<?php

use App\Models\User;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function makeFacilityResourceRegistryActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @return array{tenant: TenantModel, facility: FacilityModel}
 */
function makeFacilityResourceRegistryContext(string $tenantCode = 'TEN-RES', string $facilityCode = 'FAC-RES'): array
{
    $tenant = TenantModel::query()->create([
        'code' => strtoupper($tenantCode),
        'name' => 'Resource Tenant '.strtoupper($tenantCode),
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => strtoupper($facilityCode),
        'name' => 'Resource Facility '.strtoupper($facilityCode),
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    return [
        'tenant' => $tenant,
        'facility' => $facility,
    ];
}

function seedFacilityResourceRecord(
    FacilityModel $facility,
    string $resourceType,
    string $code,
    array $overrides = []
): FacilityResourceModel {
    $defaults = [
        'tenant_id' => $facility->tenant_id,
        'facility_id' => $facility->id,
        'resource_type' => $resourceType,
        'code' => strtoupper($code),
        'name' => 'Resource '.strtoupper($code),
        'department_id' => null,
        'service_point_type' => $resourceType === 'service_point' ? 'triage' : null,
        'ward_name' => $resourceType === 'ward_bed' ? 'WARD-A' : null,
        'bed_number' => $resourceType === 'ward_bed' ? 'A-01' : null,
        'location' => 'Block A',
        'status' => 'active',
        'status_reason' => null,
        'notes' => 'Seeded for feature test',
    ];

    return FacilityResourceModel::query()->create(array_merge($defaults, $overrides));
}

function makeWardBedChargeableItem(string $code = 'BEDDAY-GEN-WARD'): ChargeableItemModel
{
    $item = new ChargeableItemModel();
    $item->fill([
        'catalog_type' => 'bed_day',
        'charge_model' => 'per_day',
        'code' => $code,
        'name' => 'General Ward Bed-Day',
        'status' => 'active',
    ]);
    $item->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $item->id,
        'currency_code' => 'TZS',
        'unit_price' => 15000,
        'status' => 'active',
    ]);

    return $item;
}

function occupyWardBedWithActiveAdmission(FacilityResourceModel $wardBed, string $status = 'admitted'): AdmissionModel
{
    $patient = PatientModel::query()->create([
        'patient_number' => 'PTWB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Ward',
        'last_name' => 'Occupant',
        'gender' => 'male',
        'date_of_birth' => '1990-01-01',
        'phone' => '+2557000003'.random_int(10, 99),
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    return AdmissionModel::query()->create([
        'admission_number' => 'ADM'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'bed_resource_id' => $wardBed->id,
        'ward' => $wardBed->ward_name,
        'bed' => $wardBed->bed_number,
        'admitted_at' => now()->subHours(4)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Observation',
        'notes' => null,
        'status' => $status,
        'status_reason' => null,
    ]);
}

it('requires authentication for facility resource registry endpoints', function (): void {
    $this->getJson('/api/v1/platform/admin/service-points')->assertUnauthorized();

    $this->postJson('/api/v1/platform/admin/service-points', [
        'code' => 'SP-AUTH-001',
        'name' => 'Unauthenticated Service Point',
    ])->assertUnauthorized();

    $this->getJson('/api/v1/platform/admin/ward-beds')->assertUnauthorized();
});

it('creates lists and shows service points when authorized', function (): void {
    $actor = makeFacilityResourceRegistryActor([
        'platform.resources.read',
        'platform.resources.manage-service-points',
    ]);

    $response = $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/service-points', [
            'code' => 'sp-reg-001',
            'name' => 'OPD Counter 1',
            'servicePointType' => 'opd',
            'location' => 'Ground Floor',
            'notes' => 'Primary registration desk',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'SP-REG-001')
        ->assertJsonPath('data.status', 'active');

    $resourceId = $response->json('data.id');

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/service-points?q=SP-REG-001')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $resourceId);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/service-points/'.$resourceId)
        ->assertOk()
        ->assertJsonPath('data.id', $resourceId)
        ->assertJsonPath('data.servicePointType', 'opd');

    expect(
        FacilityResourceAuditLogModel::query()
            ->where('facility_resource_id', $resourceId)
            ->where('action', 'facility-resource.created')
            ->exists()
    )->toBeTrue();
});

it('enforces service-point status rules and writes transition parity metadata', function (): void {
    $actor = makeFacilityResourceRegistryActor([
        'platform.resources.manage-service-points',
    ]);
    $context = makeFacilityResourceRegistryContext('TEN-SP-STS', 'FAC-SP-STS');
    $servicePoint = seedFacilityResourceRecord($context['facility'], 'service_point', 'SP-STS-001');

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/service-points/'.$servicePoint->id.'/status', [
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/service-points/'.$servicePoint->id.'/status', [
            'status' => 'inactive',
            'reason' => 'Scheduled maintenance closure',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.statusReason', 'Scheduled maintenance closure');

    $statusLog = FacilityResourceAuditLogModel::query()
        ->where('facility_resource_id', $servicePoint->id)
        ->where('action', 'facility-resource.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('active');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('inactive');
    expect($statusLog?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('rejects lifecycle status fields on service-point detail update endpoint', function (): void {
    $actor = makeFacilityResourceRegistryActor([
        'platform.resources.manage-service-points',
    ]);
    $context = makeFacilityResourceRegistryContext('TEN-SP-UPD', 'FAC-SP-UPD');
    $servicePoint = seedFacilityResourceRecord($context['facility'], 'service_point', 'SP-UPD-001', [
        'name' => 'Original Service Point',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/service-points/'.$servicePoint->id, [
            'name' => 'Should Not Persist',
            'status' => 'inactive',
            'reason' => 'Must use status endpoint',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    $servicePoint->refresh();
    expect($servicePoint->name)->toBe('Original Service Point');
    expect($servicePoint->status)->toBe('active');
});

it('enforces ward-bed status rules and writes transition parity metadata', function (): void {
    $actor = makeFacilityResourceRegistryActor([
        'platform.resources.manage-ward-beds',
    ]);
    $context = makeFacilityResourceRegistryContext('TEN-WB-STS', 'FAC-WB-STS');
    $wardBed = seedFacilityResourceRecord($context['facility'], 'ward_bed', 'WB-STS-001', [
        'name' => 'Ward Bed A-01',
        'ward_name' => 'WARD-A',
        'bed_number' => 'A-01',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/ward-beds/'.$wardBed->id.'/status', [
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/ward-beds/'.$wardBed->id.'/status', [
            'status' => 'inactive',
            'reason' => 'Bed blocked for infection control',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.statusReason', 'Bed blocked for infection control');

    $statusLog = FacilityResourceAuditLogModel::query()
        ->where('facility_resource_id', $wardBed->id)
        ->where('action', 'facility-resource.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('active');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('inactive');
    expect($statusLog?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('rejects lifecycle status fields on ward-bed detail update endpoint', function (): void {
    $actor = makeFacilityResourceRegistryActor([
        'platform.resources.manage-ward-beds',
    ]);
    $context = makeFacilityResourceRegistryContext('TEN-WB-UPD', 'FAC-WB-UPD');
    $wardBed = seedFacilityResourceRecord($context['facility'], 'ward_bed', 'WB-UPD-001', [
        'name' => 'Original Ward Bed',
        'ward_name' => 'WARD-B',
        'bed_number' => 'B-01',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/ward-beds/'.$wardBed->id, [
            'name' => 'Should Not Persist',
            'status' => 'inactive',
            'reason' => 'Must use status endpoint',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    $wardBed->refresh();
    expect($wardBed->name)->toBe('Original Ward Bed');
    expect($wardBed->status)->toBe('active');
});

it('round-trips chargeableItemId through ward-bed create and update', function (): void {
    $actor = makeFacilityResourceRegistryActor([
        'platform.resources.manage-ward-beds',
        'platform.resources.read',
    ]);
    $context = makeFacilityResourceRegistryContext('TEN-WB-CHG', 'FAC-WB-CHG');
    $chargeableItem = makeWardBedChargeableItem();

    $wardBed = $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/ward-beds', [
            'code' => 'WB-CHG-001',
            'name' => 'Chargeable Ward Bed',
            'wardName' => 'WARD-C',
            'bedNumber' => 'C-01',
            'chargeableItemId' => $chargeableItem->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.chargeableItemId', $chargeableItem->id)
        ->json('data');

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/ward-beds/'.$wardBed['id'])
        ->assertOk()
        ->assertJsonPath('data.chargeableItemId', $chargeableItem->id);

    $otherChargeableItem = makeWardBedChargeableItem('BEDDAY-GEN-WARD-2');

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/ward-beds/'.$wardBed['id'], [
            'chargeableItemId' => $otherChargeableItem->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.chargeableItemId', $otherChargeableItem->id);

    $resource = FacilityResourceModel::query()->find($wardBed['id']);
    expect($resource?->chargeable_item_id)->toBe($otherChargeableItem->id);
});

it('lists and exports facility resource audit logs when authorized', function (): void {
    $actor = makeFacilityResourceRegistryActor([
        'platform.resources.view-audit-logs',
    ]);
    $context = makeFacilityResourceRegistryContext('TEN-RES-AUD', 'FAC-RES-AUD');
    $servicePoint = seedFacilityResourceRecord($context['facility'], 'service_point', 'SP-AUD-001');

    FacilityResourceAuditLogModel::query()->create([
        'facility_resource_id' => $servicePoint->id,
        'actor_id' => $actor->id,
        'action' => 'facility-resource.updated',
        'changes' => ['name' => ['before' => 'Resource SP-AUD-001', 'after' => 'Updated']],
        'metadata' => ['source' => 'feature-test'],
        'created_at' => now()->subMinute(),
    ]);

    FacilityResourceAuditLogModel::query()->create([
        'facility_resource_id' => $servicePoint->id,
        'actor_id' => $actor->id,
        'action' => 'facility-resource.status.updated',
        'changes' => ['status' => ['before' => 'active', 'after' => 'inactive']],
        'metadata' => ['source' => 'feature-test'],
        'created_at' => now(),
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/service-points/'.$servicePoint->id.'/audit-logs?action=facility-resource.status.updated')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'facility-resource.status.updated');

    $response = $this->actingAs($actor)
        ->get('/api/v1/platform/admin/service-points/'.$servicePoint->id.'/audit-logs/export?action=facility-resource.status.updated')
        ->assertOk()
        ->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');

    $csv = $response->streamedContent();
    expect($csv)->toContain('facility-resource.status.updated');
    expect($csv)->not->toContain('facility-resource.updated');
});

/**
 * Ward-bed admin registry gained occupancy visibility as a follow-through
 * to the Reception/Emergency/Admission/Bed-Management audit: it previously
 * had zero knowledge of admissions, so a facility admin could deactivate,
 * or lose track of, a bed that currently had a patient in it.
 */
/**
 * Postgres's LIKE is case-sensitive (unlike SQLite, which this test suite
 * runs on) — a plain where('name', 'like', ...) silently missed mixed-case
 * matches like "Dental Recovery" for a "dental" search in production.
 * Fixed via EloquentFacilityResourceRepository::applyCaseInsensitiveSearch()
 * (LOWER() on both sides). This test can't reproduce the case-sensitivity
 * bug itself on SQLite, but it documents and guards the intended
 * case-insensitive behavior going forward.
 */
it('finds ward beds by search term regardless of case', function (): void {
    $actor = makeFacilityResourceRegistryActor(['platform.resources.read']);
    $context = makeFacilityResourceRegistryContext('TEN-WB-CASE', 'FAC-WB-CASE');
    seedFacilityResourceRecord($context['facility'], 'ward_bed', 'WB-DEN-01', [
        'name' => 'Dental Recovery - Bed 01',
        'ward_name' => 'Dental Recovery',
    ]);

    foreach (['dental', 'DENTAL', 'DeNtAl'] as $term) {
        $this->actingAs($actor)
            ->getJson('/api/v1/platform/admin/ward-beds?q='.$term)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Dental Recovery - Bed 01');
    }
});

it('surfaces occupancy on the ward-bed list and detail endpoints', function (): void {
    $actor = makeFacilityResourceRegistryActor(['platform.resources.read']);
    $context = makeFacilityResourceRegistryContext('TEN-WB-OCC', 'FAC-WB-OCC');
    $occupiedBed = seedFacilityResourceRecord($context['facility'], 'ward_bed', 'WB-OCC-001');
    $vacantBed = seedFacilityResourceRecord($context['facility'], 'ward_bed', 'WB-OCC-002', ['bed_number' => 'A-02']);
    $admission = occupyWardBedWithActiveAdmission($occupiedBed);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/ward-beds')
        ->assertOk()
        ->assertJsonPath('data.0.isOccupied', true)
        ->assertJsonPath('data.0.occupiedByAdmissionId', $admission->id)
        ->assertJsonPath('data.0.occupiedByAdmissionNumber', $admission->admission_number)
        ->assertJsonPath('data.1.isOccupied', false)
        ->assertJsonPath('data.1.occupiedByAdmissionId', null);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/ward-beds/'.$occupiedBed->id)
        ->assertOk()
        ->assertJsonPath('data.isOccupied', true)
        ->assertJsonPath('data.occupiedByAdmissionNumber', $admission->admission_number);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/ward-beds/'.$vacantBed->id)
        ->assertOk()
        ->assertJsonPath('data.isOccupied', false);
});

it('blocks deactivating a ward bed that has an active admission', function (): void {
    $actor = makeFacilityResourceRegistryActor(['platform.resources.manage-ward-beds']);
    $context = makeFacilityResourceRegistryContext('TEN-WB-BLK', 'FAC-WB-BLK');
    $wardBed = seedFacilityResourceRecord($context['facility'], 'ward_bed', 'WB-BLK-001');
    $admission = occupyWardBedWithActiveAdmission($wardBed, 'transferred');

    $response = $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/ward-beds/'.$wardBed->id.'/status', [
            'status' => 'inactive',
            'reason' => 'Attempting to deactivate an occupied bed',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect($response->json('errors.status.0'))->toContain($admission->admission_number);

    $wardBed->refresh();
    expect($wardBed->status)->toBe('active');
});

it('allows deactivating a vacant ward bed once its admission is discharged', function (): void {
    $actor = makeFacilityResourceRegistryActor(['platform.resources.manage-ward-beds']);
    $context = makeFacilityResourceRegistryContext('TEN-WB-DIS', 'FAC-WB-DIS');
    $wardBed = seedFacilityResourceRecord($context['facility'], 'ward_bed', 'WB-DIS-001');
    $admission = occupyWardBedWithActiveAdmission($wardBed);
    $admission->update(['status' => 'discharged', 'discharged_at' => now()]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/ward-beds/'.$wardBed->id.'/status', [
            'status' => 'inactive',
            'reason' => 'Bed retired after discharge',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');
});

/**
 * Observation rooms are a third facility_resources resource_type
 * (dispensary/health-centre facilities without full wards), added on the
 * same generic registry as service-points and ward-beds. These tests mirror
 * the ward-bed ones above to prove the same generic create/list/status/
 * occupancy/audit behavior extends to it unchanged, plus the new
 * genderRestriction field it adds.
 */
it('creates lists and shows observation rooms when authorized', function (): void {
    $actor = makeFacilityResourceRegistryActor([
        'platform.resources.read',
        'platform.resources.manage-observation-rooms',
    ]);

    $response = $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/observation-rooms', [
            'code' => 'obs-reg-001',
            'name' => 'Female Observation Room 1',
            'roomName' => 'Observation Room',
            'roomNumber' => 'F-01',
            'genderRestriction' => 'female',
            'location' => 'Ground Floor',
            'notes' => 'Female-only observation bay',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'OBS-REG-001')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.roomName', 'Observation Room')
        ->assertJsonPath('data.roomNumber', 'F-01')
        ->assertJsonPath('data.genderRestriction', 'female');

    $resourceId = $response->json('data.id');

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/observation-rooms?q=OBS-REG-001')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $resourceId);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/observation-rooms/'.$resourceId)
        ->assertOk()
        ->assertJsonPath('data.id', $resourceId)
        ->assertJsonPath('data.genderRestriction', 'female');

    expect(
        FacilityResourceAuditLogModel::query()
            ->where('facility_resource_id', $resourceId)
            ->where('action', 'facility-resource.created')
            ->exists()
    )->toBeTrue();
});

it('enforces observation-room status rules and writes transition parity metadata', function (): void {
    $actor = makeFacilityResourceRegistryActor([
        'platform.resources.manage-observation-rooms',
    ]);
    $context = makeFacilityResourceRegistryContext('TEN-OBS-STS', 'FAC-OBS-STS');
    $room = seedFacilityResourceRecord($context['facility'], 'observation_room', 'OBS-STS-001', [
        'ward_name' => 'Observation Room',
        'bed_number' => 'F-01',
        'gender_restriction' => 'female',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/observation-rooms/'.$room->id.'/status', [
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/observation-rooms/'.$room->id.'/status', [
            'status' => 'inactive',
            'reason' => 'Deep cleaning',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.statusReason', 'Deep cleaning');
});

it('rejects lifecycle status fields on observation-room detail update endpoint', function (): void {
    $actor = makeFacilityResourceRegistryActor([
        'platform.resources.manage-observation-rooms',
    ]);
    $context = makeFacilityResourceRegistryContext('TEN-OBS-UPD', 'FAC-OBS-UPD');
    $room = seedFacilityResourceRecord($context['facility'], 'observation_room', 'OBS-UPD-001', [
        'name' => 'Original Observation Room',
        'ward_name' => 'Observation Room',
        'bed_number' => 'F-01',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/observation-rooms/'.$room->id, [
            'name' => 'Should Not Persist',
            'status' => 'inactive',
            'reason' => 'Must use status endpoint',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    $room->refresh();
    expect($room->name)->toBe('Original Observation Room');
    expect($room->status)->toBe('active');
});

it('round-trips chargeableItemId and genderRestriction through observation-room create and update', function (): void {
    $actor = makeFacilityResourceRegistryActor([
        'platform.resources.manage-observation-rooms',
        'platform.resources.read',
    ]);
    $context = makeFacilityResourceRegistryContext('TEN-OBS-CHG', 'FAC-OBS-CHG');
    $chargeableItem = makeWardBedChargeableItem('BEDDAY-OBS-FEMALE');

    $room = $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/observation-rooms', [
            'code' => 'OBS-CHG-001',
            'name' => 'Chargeable Observation Room',
            'roomName' => 'Observation Room',
            'roomNumber' => 'F-02',
            'genderRestriction' => 'female',
            'chargeableItemId' => $chargeableItem->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.chargeableItemId', $chargeableItem->id)
        ->assertJsonPath('data.genderRestriction', 'female')
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/observation-rooms/'.$room['id'], [
            'genderRestriction' => 'male',
        ])
        ->assertOk()
        ->assertJsonPath('data.genderRestriction', 'male');

    $resource = FacilityResourceModel::query()->find($room['id']);
    expect($resource?->chargeable_item_id)->toBe($chargeableItem->id);
    expect($resource?->gender_restriction)->toBe('male');
});

it('surfaces occupancy on the observation-room list and detail endpoints', function (): void {
    $actor = makeFacilityResourceRegistryActor(['platform.resources.read']);
    $context = makeFacilityResourceRegistryContext('TEN-OBS-OCC', 'FAC-OBS-OCC');
    $occupiedRoom = seedFacilityResourceRecord($context['facility'], 'observation_room', 'OBS-OCC-001', [
        'ward_name' => 'Observation Room', 'bed_number' => 'F-01',
    ]);
    $admission = occupyWardBedWithActiveAdmission($occupiedRoom);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/observation-rooms')
        ->assertOk()
        ->assertJsonPath('data.0.isOccupied', true)
        ->assertJsonPath('data.0.occupiedByAdmissionId', $admission->id);
});

it('blocks deactivating an observation room that has an active admission', function (): void {
    $actor = makeFacilityResourceRegistryActor(['platform.resources.manage-observation-rooms']);
    $context = makeFacilityResourceRegistryContext('TEN-OBS-BLK', 'FAC-OBS-BLK');
    $room = seedFacilityResourceRecord($context['facility'], 'observation_room', 'OBS-BLK-001', [
        'ward_name' => 'Observation Room', 'bed_number' => 'F-01',
    ]);
    $admission = occupyWardBedWithActiveAdmission($room, 'transferred');

    $response = $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/observation-rooms/'.$room->id.'/status', [
            'status' => 'inactive',
            'reason' => 'Attempting to deactivate an occupied room',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect($response->json('errors.status.0'))->toContain($admission->admission_number);

    $room->refresh();
    expect($room->status)->toBe('active');
});
