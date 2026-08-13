<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\PlatformRbacAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function makePlatformRbacActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function seedRbacPermission(string $name): void
{
    Permission::query()->firstOrCreate(['name' => $name]);
}

it('requires authentication for platform rbac endpoints', function (): void {
    $this->getJson('/api/v1/platform/admin/roles')
        ->assertUnauthorized();

    $this->postJson('/api/v1/platform/admin/roles', [
        'code' => 'ROLE-AUTH-001',
        'name' => 'Unauthorized Role',
    ])->assertUnauthorized();
});

it('creates lists and shows roles when authorized', function (): void {
    $actor = makePlatformRbacActor([
        'platform.rbac.read',
        'platform.rbac.manage-roles',
    ]);

    seedRbacPermission('platform.rbac.read');
    seedRbacPermission('platform.users.read');

    $response = $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/roles', [
            'code' => 'role-rbac-001',
            'name' => 'RBAC Role 001',
            'description' => 'Role for RBAC feature test',
            'permissionNames' => ['platform.rbac.read', 'platform.users.read'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'ROLE-RBAC-001')
        ->assertJsonPath('data.status', 'active');

    $roleId = $response->json('data.id');

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/roles?q=ROLE-RBAC-001')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $roleId);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/roles/'.$roleId)
        ->assertOk()
        ->assertJsonPath('data.id', $roleId)
        ->assertJsonPath('data.code', 'ROLE-RBAC-001');

    expect(
        PlatformRbacAuditLogModel::query()
            ->where('target_type', 'role')
            ->where('target_id', $roleId)
            ->where('action', 'platform-rbac.role.created')
            ->exists()
    )->toBeTrue();
});

it('enforces lifecycle guardrails and status transition parity metadata on role updates', function (): void {
    $actor = makePlatformRbacActor([
        'platform.rbac.manage-roles',
    ]);

    seedRbacPermission('platform.rbac.read');

    $role = RoleModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'code' => 'ROLE-UPD-001',
        'name' => 'Role Update 001',
        'status' => 'active',
        'description' => null,
        'is_system' => false,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/roles/'.$role->id, [
            'name' => 'Should Not Persist',
            'permissionNames' => ['platform.rbac.read'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissionNames']);

    $role->refresh();
    expect($role->name)->toBe('Role Update 001');

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/roles/'.$role->id, [
            'status' => 'inactive',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $log = PlatformRbacAuditLogModel::query()
        ->where('action', 'platform-rbac.role.updated')
        ->where('target_type', 'role')
        ->where('target_id', $role->id)
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull();
    expect($log?->metadata['transition']['from'] ?? null)->toBe('active');
    expect($log?->metadata['transition']['to'] ?? null)->toBe('inactive');
});

it('syncs role permissions and writes audit logs when authorized', function (): void {
    $actor = makePlatformRbacActor([
        'platform.rbac.manage-roles',
    ]);

    seedRbacPermission('platform.rbac.read');
    seedRbacPermission('platform.users.read');
    seedRbacPermission('platform.users.create');

    $role = RoleModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'code' => 'ROLE-PERM-001',
        'name' => 'Role Permission 001',
        'status' => 'active',
        'description' => null,
        'is_system' => false,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/roles/'.$role->id.'/permissions', [
            'permissionNames' => ['platform.rbac.read', 'platform.users.create'],
        ])
        ->assertOk()
        ->assertJsonPath('data.permissionsCount', 2);

    expect(
        PlatformRbacAuditLogModel::query()
            ->where('action', 'platform-rbac.role.permissions.synced')
            ->where('target_id', $role->id)
            ->exists()
    )->toBeTrue();
});

it('lists platform rbac audit logs with filters when authorized', function (): void {
    $actor = makePlatformRbacActor([
        'platform.rbac.view-audit-logs',
    ]);

    $role = RoleModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'code' => 'ROLE-AUD-001',
        'name' => 'Role Audit 001',
        'status' => 'active',
        'description' => null,
        'is_system' => false,
    ]);

    PlatformRbacAuditLogModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'actor_id' => $actor->id,
        'action' => 'platform-rbac.role.updated',
        'target_type' => 'role',
        'target_id' => $role->id,
        'changes' => ['name' => ['before' => 'Role Audit 001', 'after' => 'Role Audit Updated']],
        'metadata' => ['source' => 'feature-test'],
        'created_at' => now()->subMinute(),
    ]);

    PlatformRbacAuditLogModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'actor_id' => $actor->id,
        'action' => 'platform-rbac.role.permissions.synced',
        'target_type' => 'role',
        'target_id' => $role->id,
        'changes' => ['permission_names' => ['before' => [], 'after' => ['platform.rbac.read']]],
        'metadata' => ['source' => 'feature-test'],
        'created_at' => now(),
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/rbac-audit-logs?action=platform-rbac.role.permissions.synced')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'platform-rbac.role.permissions.synced');
});
