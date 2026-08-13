<?php

use App\Models\User;
use App\Modules\Staff\Infrastructure\Models\ClinicalSpecialtyAuditLogModel;
use App\Modules\Staff\Infrastructure\Models\ClinicalSpecialtyModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileSpecialtyModel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function makeClinicalSpecialtyActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @return array<string, mixed>
 */
function clinicalSpecialtyPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'sp-ctg-001',
        'name' => 'Cardiology',
        'description' => 'Cardiology specialty baseline',
    ], $overrides);
}

function makeSpecialtyAssignedStaffProfile(): StaffProfileModel
{
    $targetUser = User::factory()->create();

    return StaffProfileModel::query()->create([
        'user_id' => $targetUser->id,
        'employee_number' => 'STF-SPC-'.strtoupper(Str::random(8)),
        'department' => 'Medical',
        'job_title' => 'Medical Officer',
        'professional_license_number' => 'LIC-SPC-'.strtoupper(Str::random(6)),
        'license_type' => 'Full Registration',
        'phone_extension' => '210',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);
}

it('requires authentication for clinical specialty list and creation', function (): void {
    $this->getJson('/api/v1/specialties')->assertUnauthorized();

    $this->postJson('/api/v1/specialties', clinicalSpecialtyPayload())->assertUnauthorized();
});

it('creates and lists clinical specialties when authorized', function (): void {
    $actor = makeClinicalSpecialtyActor([
        'specialties.read',
        'specialties.create',
    ]);

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/specialties', clinicalSpecialtyPayload())
        ->assertCreated()
        ->assertJsonPath('data.code', 'SP-CTG-001')
        ->assertJsonPath('data.name', 'Cardiology')
        ->assertJsonPath('data.status', 'active')
        ->json('data');

    $this->actingAs($actor)
        ->getJson('/api/v1/specialties?q=SP-CTG-001')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $created['id'])
        ->assertJsonPath('data.0.code', 'SP-CTG-001');
});

it('updates clinical specialty metadata and writes audit logs', function (): void {
    $actor = makeClinicalSpecialtyActor([
        'specialties.read',
        'specialties.create',
        'specialties.update',
    ]);

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/specialties', clinicalSpecialtyPayload())
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/specialties/'.$created['id'], [
            'name' => 'Advanced Cardiology',
            'description' => 'Updated cardiology specialization scope.',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Advanced Cardiology')
        ->assertJsonPath('data.description', 'Updated cardiology specialization scope.');

    expect(
        ClinicalSpecialtyAuditLogModel::query()
            ->where('specialty_id', $created['id'])
            ->where('action', 'specialty.updated')
            ->exists()
    )->toBeTrue();
});

it('rejects status lifecycle fields on clinical specialty detail update endpoint', function (): void {
    $actor = makeClinicalSpecialtyActor([
        'specialties.create',
        'specialties.update',
    ]);

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/specialties', clinicalSpecialtyPayload([
            'code' => 'sp-ctg-'.strtolower(Str::random(4)),
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/specialties/'.$created['id'], [
            'name' => 'Should Not Persist',
            'status' => 'inactive',
            'reason' => 'Lifecycle update attempt',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status', 'reason']);

    $specialty = ClinicalSpecialtyModel::query()->findOrFail($created['id']);
    expect($specialty->name)->toBe('Cardiology');
    expect($specialty->status)->toBe('active');
});

it('enforces reason for inactive status and writes transition metadata', function (): void {
    $actor = makeClinicalSpecialtyActor([
        'specialties.create',
        'specialties.update-status',
    ]);

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/specialties', clinicalSpecialtyPayload([
            'code' => 'sp-ctg-'.strtolower(Str::random(4)),
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/specialties/'.$created['id'].'/status', [
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/specialties/'.$created['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Service line temporarily paused',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.statusReason', 'Service line temporarily paused');

    $statusLog = ClinicalSpecialtyAuditLogModel::query()
        ->where('specialty_id', $created['id'])
        ->where('action', 'specialty.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('active');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('inactive');
    expect($statusLog?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('lists assigned staff for a specialty when authorized', function (): void {
    $actor = makeClinicalSpecialtyActor([
        'specialties.read',
        'staff.specialties.read',
    ]);

    $specialty = ClinicalSpecialtyModel::query()->create([
        'code' => 'SP-ONC-001',
        'name' => 'Oncology',
        'description' => 'Oncology service line',
        'status' => 'active',
        'status_reason' => null,
    ]);

    $primaryProfile = makeSpecialtyAssignedStaffProfile();
    $secondaryProfile = makeSpecialtyAssignedStaffProfile();

    StaffProfileSpecialtyModel::query()->create([
        'staff_profile_id' => $primaryProfile->id,
        'specialty_id' => $specialty->id,
        'is_primary' => true,
    ]);

    StaffProfileSpecialtyModel::query()->create([
        'staff_profile_id' => $secondaryProfile->id,
        'specialty_id' => $specialty->id,
        'is_primary' => false,
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/specialties/'.$specialty->id.'/assigned-staff')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.id', $primaryProfile->id)
        ->assertJsonPath('data.0.isPrimary', true)
        ->assertJsonPath('data.1.id', $secondaryProfile->id)
        ->assertJsonPath('data.1.isPrimary', false);
});
