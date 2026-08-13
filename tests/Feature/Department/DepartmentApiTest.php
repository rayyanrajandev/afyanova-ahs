<?php

use App\Models\User;
use App\Modules\Department\Infrastructure\Models\DepartmentAuditLogModel;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function makeDepartmentActor(array $permissions = []): User
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
function departmentPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'dept-opd-001',
        'name' => 'OPD',
        'serviceType' => 'outpatient',
        'isPatientFacing' => true,
        'isAppointmentable' => true,
        'description' => 'Outpatient department',
    ], $overrides);
}

it('requires authentication for department list and creation', function (): void {
    $this->getJson('/api/v1/departments')->assertUnauthorized();

    $this->postJson('/api/v1/departments', departmentPayload())->assertUnauthorized();
});

it('creates and lists departments when authorized', function (): void {
    $actor = makeDepartmentActor([
        'departments.read',
        'departments.create',
    ]);

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/departments', departmentPayload())
        ->assertCreated()
        ->assertJsonPath('data.code', 'DEPT-OPD-001')
        ->assertJsonPath('data.name', 'OPD')
        ->assertJsonPath('data.isPatientFacing', true)
        ->assertJsonPath('data.isAppointmentable', true)
        ->assertJsonPath('data.status', 'active')
        ->json('data');

    $this->actingAs($actor)
        ->getJson('/api/v1/departments?q=DEPT-OPD-001')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $created['id'])
        ->assertJsonPath('data.0.code', 'DEPT-OPD-001');
});

it('updates department metadata and writes audit logs', function (): void {
    $actor = makeDepartmentActor([
        'departments.create',
        'departments.update',
    ]);

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/departments', departmentPayload([
            'code' => 'dept-opd-'.strtolower(Str::random(4)),
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/departments/'.$created['id'], [
            'name' => 'Advanced OPD',
            'isPatientFacing' => true,
            'isAppointmentable' => false,
            'description' => 'Expanded outpatient services.',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Advanced OPD')
        ->assertJsonPath('data.isPatientFacing', true)
        ->assertJsonPath('data.isAppointmentable', false)
        ->assertJsonPath('data.description', 'Expanded outpatient services.');

    expect(
        DepartmentAuditLogModel::query()
            ->where('department_id', $created['id'])
            ->where('action', 'department.updated')
            ->exists()
    )->toBeTrue();

    $department = DepartmentModel::query()->findOrFail($created['id']);
    expect($department->is_patient_facing)->toBeTrue();
    expect($department->is_appointmentable)->toBeFalse();
});

it('rejects status lifecycle fields on department detail update endpoint', function (): void {
    $actor = makeDepartmentActor([
        'departments.create',
        'departments.update',
    ]);

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/departments', departmentPayload([
            'code' => 'dept-opd-'.strtolower(Str::random(4)),
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/departments/'.$created['id'], [
            'name' => 'Should Not Persist',
            'status' => 'inactive',
            'reason' => 'Lifecycle update attempt',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status', 'reason']);

    $department = DepartmentModel::query()->findOrFail($created['id']);
    expect($department->name)->toBe('OPD');
    expect($department->status)->toBe('active');
});

it('enforces reason for inactive status and writes transition metadata', function (): void {
    $actor = makeDepartmentActor([
        'departments.create',
        'departments.update-status',
    ]);

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/departments', departmentPayload([
            'code' => 'dept-opd-'.strtolower(Str::random(4)),
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/departments/'.$created['id'].'/status', [
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/departments/'.$created['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Temporary service pause',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.statusReason', 'Temporary service pause');

    $statusLog = DepartmentAuditLogModel::query()
        ->where('department_id', $created['id'])
        ->where('action', 'department.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('active');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('inactive');
    expect($statusLog?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['reason_provided'] ?? null)->toBeTrue();
});
