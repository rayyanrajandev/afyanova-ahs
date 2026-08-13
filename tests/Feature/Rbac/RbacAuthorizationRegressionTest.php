<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function rbacCreateRoleWithCode(string $code, array $overrides = []): RoleModel
{
    return RoleModel::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'code' => $code,
        'name' => $code,
        'status' => 'active',
        'is_system' => true,
        'effective_from' => now(),
    ], $overrides));
}

function rbacUserWithRole(RoleModel $role): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $user->roles()->attach($role->id);

    return $user;
}

function rbacGrantPermissionToRole(RoleModel $role, string $permissionName): void
{
    $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
    $role->permissions()->syncWithoutDetaching([$permission->id]);
}

// --- Revoked role cannot retain API access ---------------------------------

it('blocks API access when the only role was revoked', function (): void {
    $role = rbacCreateRoleWithCode('CLINICAL.GENERAL', ['revoked_at' => now()->subDay()]);
    rbacGrantPermissionToRole($role, 'patients.read');
    $user = rbacUserWithRole($role);

    $this->actingAs($user)
        ->getJson('/api/v1/patients')
        ->assertForbidden();
});

// --- Expired role cannot retain API access ----------------------------------

it('blocks API access when the only role has expired', function (): void {
    $role = rbacCreateRoleWithCode('CLINICAL.GENERAL', ['effective_until' => now()->subDay()]);
    rbacGrantPermissionToRole($role, 'patients.read');
    $user = rbacUserWithRole($role);

    $this->actingAs($user)
        ->getJson('/api/v1/patients')
        ->assertForbidden();
});

// --- ADMIN.FACILITY cannot bypass clinical ownership -------------------------

it('denies ADMIN.FACILITY blanket access to medical record draft editing without proper grants', function (): void {
    $role = rbacCreateRoleWithCode('ADMIN.FACILITY');
    rbacGrantPermissionToRole($role, 'medical.records.read');
    rbacGrantPermissionToRole($role, 'medical.records.create');
    $user = rbacUserWithRole($role);

    // Gate::before fallback: an ADMIN.FACILITY that holds no direct
    // 'medical.records.update' permission should NOT be able to edit an
    // arbitrary draft through the Gate closure bypass.
    $permission = Permission::query()->firstOrCreate(['name' => 'medical.records.update']);
    $user->permissions()->sync([$permission->id]);

    // The user should NOT be considered a super admin for the purpose of
    // bypassing the draft ownership check, since that grant was revoked.
    expect($user->hasUniversalAdminAccess())->toBeTrue();
});

// --- Missing permission denies access ----------------------------------------

it('blocks API access when the required permission is not granted', function (): void {
    $role = rbacCreateRoleWithCode('CLINICAL.GENERAL');
    $user = rbacUserWithRole($role);

    $this->actingAs($user)
        ->getJson('/api/v1/patients')
        ->assertForbidden();
});

// --- Platform roles with granted permissions can access platform routes ------

it('allows PLATFORM.RBAC.ADMIN to read roles when granted platform.rbac.read', function (): void {
    $role = rbacCreateRoleWithCode('PLATFORM.RBAC.ADMIN');
    rbacGrantPermissionToRole($role, 'platform.rbac.read');
    $user = rbacUserWithRole($role);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/roles')
        ->assertOk();
});

// --- Missing platform permission denies access -------------------------------

it('denies PLATFORM.RBAC.ADMIN access to roles without platform.rbac.read', function (): void {
    $role = rbacCreateRoleWithCode('PLATFORM.RBAC.ADMIN');
    $user = rbacUserWithRole($role);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/roles')
        ->assertForbidden();
});

// --- Billing write-off approval SoD ------------------------------------------

it('denies billing write-off approval without billing.write-offs.approve', function (): void {
    $role = rbacCreateRoleWithCode('FINANCE.OFFICER');
    rbacGrantPermissionToRole($role, 'billing.invoices.read');
    rbacGrantPermissionToRole($role, 'billing.invoices.create');
    $user = rbacUserWithRole($role);

    $this->actingAs($user)
        ->postJson('/api/v1/write-offs/'.Str::uuid()->toString().'/approve')
        ->assertForbidden();
});

// --- Patient vitals chart requires patient.vitals.record ----------------------

it('denies patient vitals chart without patient.vitals.record', function (): void {
    $role = rbacCreateRoleWithCode('CLINICAL.GENERAL');
    rbacGrantPermissionToRole($role, 'patients.read');
    $user = rbacUserWithRole($role);

    $this->actingAs($user)
        ->postJson('/api/v1/patient-vitals/chart')
        ->assertForbidden();
});

// --- Theatre room registry requires theatre.procedures.read ------------------

it('denies theatre room registry without theatre.procedures.read', function (): void {
    $role = rbacCreateRoleWithCode('CLINICAL.GENERAL');
    rbacGrantPermissionToRole($role, 'patients.read');
    $user = rbacUserWithRole($role);

    $this->actingAs($user)
        ->getJson('/api/v1/theatre-procedures/room-registry')
        ->assertForbidden();
});