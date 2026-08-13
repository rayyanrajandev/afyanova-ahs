<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Regression + verification suite for RBAC Remediation Plan Phase 1
 * (see RBAC_Remediation_Plan.md, Tasks 1.1 and 1.2).
 *
 * Task 1.1 covers App\Models\User::isFacilitySuperAdmin() /
 * isPlatformSuperAdmin(): a role granting universal admin bypass must be
 * active, unrevoked, and unexpired. Task 1.2 covers the active-role gate
 * (`user.has-role` middleware) now being applied to routes/api.php and
 * routes/billing-phase1.php, not just routes/web.php.
 */
function createRoleWithCode(string $code, array $overrides = []): RoleModel
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

function userWithRole(RoleModel $role): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $user->roles()->attach($role->id);

    return $user;
}

// --- Task 1.1: model-level bypass checks -----------------------------------

it('still recognizes an active ADMIN.FACILITY role as a facility super admin', function (): void {
    // hasUniversalAdminAccess() itself is unchanged by this fix — it still
    // reports an active ADMIN.FACILITY role as a facility super admin (used
    // by the ~20 ownership/scoping-convenience call sites reviewed in
    // RBAC_Remediation_Plan.md Phase 2). What changed in Phase 2 is that
    // hasPermissionTo()/permissionNames() no longer use this flag as a
    // blanket "grant everything" shortcut — see RbacFacilityAdminScopeTest.
    $role = createRoleWithCode('ADMIN.FACILITY');
    $user = userWithRole($role);

    expect($user->hasUniversalAdminAccess())->toBeTrue()
        ->and($user->hasPermissionTo('anything.at.all'))->toBeFalse();
});

it('denies universal admin access when the ADMIN.FACILITY role has been revoked', function (): void {
    $role = createRoleWithCode('ADMIN.FACILITY', ['revoked_at' => now()->subDay()]);
    $user = userWithRole($role);

    expect($user->hasUniversalAdminAccess())->toBeFalse()
        ->and($user->hasPermissionTo('anything.at.all'))->toBeFalse();
});

it('denies universal admin access when the ADMIN.FACILITY role has expired', function (): void {
    $role = createRoleWithCode('ADMIN.FACILITY', ['effective_until' => now()->subDay()]);
    $user = userWithRole($role);

    expect($user->hasUniversalAdminAccess())->toBeFalse();
});

it('denies universal admin access when the ADMIN.FACILITY role status is not active', function (): void {
    $role = createRoleWithCode('ADMIN.FACILITY', ['status' => 'inactive']);
    $user = userWithRole($role);

    expect($user->hasUniversalAdminAccess())->toBeFalse();
});

it('still grants universal admin access to a user with an active PLATFORM.SUPER.ADMIN role', function (): void {
    $role = createRoleWithCode('PLATFORM.SUPER.ADMIN');
    $user = userWithRole($role);

    expect($user->hasUniversalAdminAccess())->toBeTrue();
});

it('denies universal admin access when the PLATFORM.SUPER.ADMIN role has been revoked', function (): void {
    $role = createRoleWithCode('PLATFORM.SUPER.ADMIN', ['revoked_at' => now()->subDay()]);
    $user = userWithRole($role);

    expect($user->hasUniversalAdminAccess())->toBeFalse();
});

it('still allows the is_platform_admin flag to grant access regardless of role rows', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'is_platform_admin' => true,
    ]);

    expect($user->hasUniversalAdminAccess())->toBeTrue();
});

// --- Route-level check: the api.php route itself must not grant the bypass --

it('blocks api/v1 routes for a user whose only role is a revoked ADMIN.FACILITY grant', function (): void {
    // NOTE: routes/api.php and routes/billing-phase1.php deliberately do NOT carry
    // the 'user.has-role' middleware (see RBAC_Remediation_Plan.md Task 1.2 note) —
    // that middleware assumes every permission arrives via a role, but this codebase
    // also supports direct user-level permission grants (User::givePermissionTo())
    // with no role at all, which 'user.has-role' would incorrectly block wholesale.
    // Task 1.1 (this test) instead closes the bypass at its source in
    // User::isFacilitySuperAdmin(), so the user falls through to the ordinary
    // can:patients.read check and is denied there.
    $role = createRoleWithCode('ADMIN.FACILITY', ['revoked_at' => now()->subDay()]);
    $user = userWithRole($role);

    $this->actingAs($user)
        ->getJson('/api/v1/patients')
        ->assertForbidden();
});

it('still allows api/v1 routes for a user with an active role and the matching permission', function (): void {
    $role = createRoleWithCode('CLINICAL.GENERAL');
    $permission = Permission::query()->firstOrCreate(['name' => 'patients.read']);
    $role->permissions()->syncWithoutDetaching([$permission->id]);
    $user = userWithRole($role);

    $this->actingAs($user)
        ->getJson('/api/v1/patients')
        ->assertOk();
});

it('still blocks api/v1 routes for a user with an active role but without the required permission', function (): void {
    $role = createRoleWithCode('CLINICAL.GENERAL');
    $user = userWithRole($role);

    $this->actingAs($user)
        ->getJson('/api/v1/patients')
        ->assertForbidden();
});
