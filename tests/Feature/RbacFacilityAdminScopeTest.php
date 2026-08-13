<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\Billing\Application\Services\BillingQueueChannelAuthorizer;
use App\Modules\Platform\Application\UseCases\CreatePlatformRoleUseCase;
use App\Modules\Platform\Application\UseCases\SyncPlatformRolePermissionsUseCase;
use App\Modules\Platform\Application\Exceptions\PlatformRoleProtectedException;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\PatientFlow\Application\Services\PatientFlowBoardChannelAuthorizer;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Regression + verification suite for RBAC Remediation Plan Phase 2
 * (see RBAC_Remediation_Plan.md): stops ADMIN.FACILITY from being treated as
 * a universal permission bypass in User::hasPermissionTo()/permissionNames(),
 * and closes 6 backend call sites that read hasUniversalAdminAccess()/
 * isFacilitySuperAdminAccess() directly as their own escalation shortcut
 * (independent of hasPermissionTo()). Ownership/scoping conveniences (e.g.
 * encounter/appointment status overrides) were reviewed and intentionally
 * left unchanged — see the plan doc for the full per-call-site reasoning.
 */
function createRoleWithCode2(string $code, array $overrides = []): RoleModel
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

function userWithRole2(RoleModel $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->roles()->attach($role->id);

    return $user;
}

function grantPermission(RoleModel $role, string $name): void
{
    $permission = Permission::query()->firstOrCreate(['name' => $name]);
    $role->permissions()->syncWithoutDetaching([$permission->id]);
}

// --- Core fix: hasPermissionTo()/permissionNames() no longer treat
// ADMIN.FACILITY as "grant everything" ---------------------------------------

it('only grants a facility admin the permissions actually attached to their role', function (): void {
    $role = createRoleWithCode2('ADMIN.FACILITY');
    grantPermission($role, 'patients.read');
    $user = userWithRole2($role);

    expect($user->hasPermissionTo('patients.read'))->toBeTrue()
        ->and($user->hasPermissionTo('platform.rbac.manage-roles'))->toBeFalse()
        ->and($user->hasPermissionTo('platform.cross-tenant.read'))->toBeFalse();
});

it('permissionNames() for a facility admin is not the full system permission catalog', function (): void {
    Permission::query()->firstOrCreate(['name' => 'some.other.module.permission']);

    $role = createRoleWithCode2('ADMIN.FACILITY');
    grantPermission($role, 'patients.read');
    $user = userWithRole2($role);

    $names = $user->permissionNames();

    expect($names)->toContain('patients.read')
        ->and($names)->not->toContain('some.other.module.permission')
        ->and($names)->not->toContain('platform.rbac.manage-roles');
});

it('still grants a true platform super admin every permission (unchanged)', function (): void {
    Permission::query()->firstOrCreate(['name' => 'some.other.module.permission']);

    $role = createRoleWithCode2('PLATFORM.SUPER.ADMIN');
    $user = userWithRole2($role);

    expect($user->hasPermissionTo('some.other.module.permission'))->toBeTrue()
        ->and($user->permissionNames())->toContain('some.other.module.permission');
});

// --- CreatePlatformRoleUseCase / SyncPlatformRolePermissionsUseCase: a
// facility admin must not be able to mint or grant platform.rbac.* escalation
// permissions on any role --------------------------------------------------

it('blocks a facility admin from creating a role that carries platform.rbac.manage-roles', function (): void {
    Permission::query()->firstOrCreate(['name' => 'platform.rbac.manage-roles']);

    $adminRole = createRoleWithCode2('ADMIN.FACILITY');
    $actor = userWithRole2($adminRole);

    app(CreatePlatformRoleUseCase::class)->execute(
        payload: [
            'code' => 'sneaky-escalation-role',
            'name' => 'Sneaky Escalation Role',
            'permission_names' => ['platform.rbac.manage-roles'],
        ],
        actorId: $actor->id,
    );
})->throws(PlatformRoleProtectedException::class);

it('allows a true platform super admin to create a role that carries platform.rbac.manage-roles', function (): void {
    Permission::query()->firstOrCreate(['name' => 'platform.rbac.manage-roles']);

    $superRole = createRoleWithCode2('PLATFORM.SUPER.ADMIN');
    $actor = userWithRole2($superRole);

    $role = app(CreatePlatformRoleUseCase::class)->execute(
        payload: [
            'code' => 'legit-rbac-admin-role',
            'name' => 'Legit RBAC Admin Role',
            'permission_names' => ['platform.rbac.manage-roles'],
        ],
        actorId: $actor->id,
    );

    expect($role['code'] ?? $role->code ?? null)->toBe('LEGIT-RBAC-ADMIN-ROLE');
});

it('blocks a facility admin from granting an existing role platform.rbac.manage-user-roles', function (): void {
    Permission::query()->firstOrCreate(['name' => 'platform.rbac.manage-user-roles']);

    $adminRole = createRoleWithCode2('ADMIN.FACILITY');
    $actor = userWithRole2($adminRole);

    $targetRole = RoleModel::query()->create([
        'id' => (string) Str::uuid(),
        'code' => 'SOME-OTHER-ROLE',
        'name' => 'Some Other Role',
        'status' => 'active',
        'is_system' => false,
    ]);

    app(SyncPlatformRolePermissionsUseCase::class)->execute(
        roleId: $targetRole->id,
        permissionNames: ['platform.rbac.manage-user-roles'],
        actorId: $actor->id,
    );
})->throws(PlatformRoleProtectedException::class);

// --- Platform RBAC route gate: after the core fix, a facility admin with no
// platform.rbac.* grant of their own can no longer even reach the platform
// role-management endpoints (defense in depth beyond the use-case checks
// above) -----------------------------------------------------------------

it('blocks a facility admin at the route level from platform role-management endpoints', function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $adminRole = createRoleWithCode2('ADMIN.FACILITY');
    $actor = userWithRole2($adminRole);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/roles')
        ->assertForbidden();

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/roles', [
            'code' => 'route-level-role',
            'name' => 'Route Level Role',
        ])
        ->assertForbidden();
});

// --- Broadcast channel authorizers: a facility admin must still hold an
// active facility_user membership to subscribe to another facility's
// real-time channel — cross-facility subscription is no longer implied by
// isFacilitySuperAdminAccess() alone ---------------------------------------

it('forbids the patient-flow board channel for a facility admin without membership in that facility', function (): void {
    $adminRole = createRoleWithCode2('ADMIN.FACILITY');
    $user = userWithRole2($adminRole);
    $user->givePermissionTo('appointments.read');

    expect(app(PatientFlowBoardChannelAuthorizer::class)->authorize($user, (string) Str::uuid()))
        ->toBeFalse();
});

it('still authorizes the patient-flow board channel for a true platform super admin regardless of facility membership', function (): void {
    $superRole = createRoleWithCode2('PLATFORM.SUPER.ADMIN');
    $user = userWithRole2($superRole);

    expect(app(PatientFlowBoardChannelAuthorizer::class)->authorize($user, (string) Str::uuid()))
        ->toBeTrue();
});

it('forbids the billing queue channel for a facility admin without membership in that facility', function (): void {
    $adminRole = createRoleWithCode2('ADMIN.FACILITY');
    $user = userWithRole2($adminRole);
    $user->givePermissionTo('billing.invoices.read');

    expect(app(BillingQueueChannelAuthorizer::class)->authorize($user, (string) Str::uuid()))
        ->toBeFalse();
});

it('still authorizes the billing queue channel for a true platform super admin regardless of facility membership', function (): void {
    $superRole = createRoleWithCode2('PLATFORM.SUPER.ADMIN');
    $user = userWithRole2($superRole);

    expect(app(BillingQueueChannelAuthorizer::class)->authorize($user, (string) Str::uuid()))
        ->toBeTrue();
});
