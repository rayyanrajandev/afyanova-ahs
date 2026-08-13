<?php

use App\Models\User;
use App\Modules\Staff\Infrastructure\Models\ClinicalPrivilegeCatalogAuditLogModel;
use App\Modules\Staff\Infrastructure\Models\ClinicalPrivilegeCatalogModel;
use App\Modules\Staff\Infrastructure\Models\ClinicalSpecialtyModel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function makeClinicalPrivilegeCatalogActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function makeClinicalPrivilegeCatalogSpecialty(?string $code = null): ClinicalSpecialtyModel
{
    return ClinicalSpecialtyModel::query()->create([
        'code' => strtoupper($code ?? 'SP-PRV-'.Str::random(6)),
        'name' => 'Privilege Catalog Specialty '.strtoupper(Str::random(4)),
        'description' => 'Specialty used for privilege catalog API tests.',
        'status' => 'active',
        'status_reason' => null,
    ]);
}

/**
 * @return array<string, mixed>
 */
function clinicalPrivilegeCatalogPayload(string $specialtyId, array $overrides = []): array
{
    return array_merge([
        'specialtyId' => $specialtyId,
        'code' => 'prv-cat-001',
        'name' => 'Outpatient Consultation Coverage',
        'description' => 'Governed privilege template for outpatient consultation.',
        'cadreCode' => 'clinical_officer',
        'facilityType' => 'hospital',
    ], $overrides);
}

it('requires authentication for privilege catalog list and creation', function (): void {
    $specialty = makeClinicalPrivilegeCatalogSpecialty('SP-PRV-AUTH');

    $this->getJson('/api/v1/privilege-catalogs')->assertUnauthorized();

    $this->postJson('/api/v1/privilege-catalogs', clinicalPrivilegeCatalogPayload($specialty->id))->assertUnauthorized();
});

it('creates and lists privilege catalog templates when authorized', function (): void {
    $actor = makeClinicalPrivilegeCatalogActor([
        'staff.privileges.read',
        'staff.privileges.create',
    ]);
    $specialty = makeClinicalPrivilegeCatalogSpecialty('SP-PRV-LST');

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/privilege-catalogs', clinicalPrivilegeCatalogPayload($specialty->id))
        ->assertCreated()
        ->assertJsonPath('data.code', 'PRV-CAT-001')
        ->assertJsonPath('data.specialtyId', $specialty->id)
        ->assertJsonPath('data.status', 'active')
        ->json('data');

    $this->actingAs($actor)
        ->getJson('/api/v1/privilege-catalogs?q=PRV-CAT-001')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $created['id'])
        ->assertJsonPath('data.0.code', 'PRV-CAT-001');
});

it('updates privilege catalog metadata and writes audit logs', function (): void {
    $actor = makeClinicalPrivilegeCatalogActor([
        'staff.privileges.create',
        'staff.privileges.update',
    ]);
    $specialty = makeClinicalPrivilegeCatalogSpecialty('SP-PRV-UPD');

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/privilege-catalogs', clinicalPrivilegeCatalogPayload($specialty->id, [
            'code' => 'prv-cat-upd',
        ]))
        ->assertCreated()
        ->json('data');

    $nextSpecialty = makeClinicalPrivilegeCatalogSpecialty('SP-PRV-UPD2');

    $this->actingAs($actor)
        ->patchJson('/api/v1/privilege-catalogs/'.$created['id'], [
            'specialtyId' => $nextSpecialty->id,
            'name' => 'Advanced Outpatient Consultation Coverage',
            'cadreCode' => 'medical_doctor',
        ])
        ->assertOk()
        ->assertJsonPath('data.specialtyId', $nextSpecialty->id)
        ->assertJsonPath('data.name', 'Advanced Outpatient Consultation Coverage')
        ->assertJsonPath('data.cadreCode', 'medical_doctor');

    expect(
        ClinicalPrivilegeCatalogAuditLogModel::query()
            ->where('privilege_catalog_id', $created['id'])
            ->where('action', 'privilege-catalog.updated')
            ->exists()
    )->toBeTrue();
});

it('enforces reason for inactive privilege catalog status and writes transition metadata', function (): void {
    $actor = makeClinicalPrivilegeCatalogActor([
        'staff.privileges.create',
        'staff.privileges.update-status',
    ]);
    $specialty = makeClinicalPrivilegeCatalogSpecialty('SP-PRV-STS');

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/privilege-catalogs', clinicalPrivilegeCatalogPayload($specialty->id, [
            'code' => 'prv-cat-sts',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/privilege-catalogs/'.$created['id'].'/status', [
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/privilege-catalogs/'.$created['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Template retired after service redesign',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.statusReason', 'Template retired after service redesign');

    $statusLog = ClinicalPrivilegeCatalogAuditLogModel::query()
        ->where('privilege_catalog_id', $created['id'])
        ->where('action', 'privilege-catalog.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('active');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('inactive');
    expect($statusLog?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('lists privilege catalog audit logs when authorized', function (): void {
    $actor = makeClinicalPrivilegeCatalogActor([
        'staff.privileges.create',
        'staff.privileges.update',
        'staff.privileges.view-audit-logs',
    ]);
    $specialty = makeClinicalPrivilegeCatalogSpecialty('SP-PRV-AUD');

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/privilege-catalogs', clinicalPrivilegeCatalogPayload($specialty->id, [
            'code' => 'prv-cat-aud',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/privilege-catalogs/'.$created['id'], [
            'description' => 'Updated for privilege catalog audit verification.',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->getJson('/api/v1/privilege-catalogs/'.$created['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.privilegeCatalogId', $created['id']);
});

it('rejects duplicate privilege catalog code within the same scope', function (): void {
    $actor = makeClinicalPrivilegeCatalogActor([
        'staff.privileges.create',
    ]);
    $specialty = makeClinicalPrivilegeCatalogSpecialty('SP-PRV-DUP');

    $this->actingAs($actor)
        ->postJson('/api/v1/privilege-catalogs', clinicalPrivilegeCatalogPayload($specialty->id, [
            'code' => 'prv-cat-dup',
        ]))
        ->assertCreated();

    $this->actingAs($actor)
        ->postJson('/api/v1/privilege-catalogs', clinicalPrivilegeCatalogPayload($specialty->id, [
            'code' => 'prv-cat-dup',
            'name' => 'Duplicate template',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    expect(
        ClinicalPrivilegeCatalogModel::query()
            ->where('code', 'PRV-CAT-DUP')
            ->count()
    )->toBe(1);
});
