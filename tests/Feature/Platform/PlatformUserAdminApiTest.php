<?php

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\PlatformUserAdminAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\PlatformRbacAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function makePlatformUserAdminActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function makePrivilegedPlatformTargetUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('platform.cross-tenant.read');

    return $user;
}

it('requires authentication for platform user status counts and audit export', function (): void {
    $target = User::factory()->create();

    $this->getJson('/api/v1/platform/admin/users/status-counts')
        ->assertUnauthorized();

    $this->get('/api/v1/platform/admin/users/'.$target->id.'/audit-logs/export')
        ->assertRedirect('/login');
});

it('returns platform user status counts with search filtering', function (): void {
    $actor = makePlatformUserAdminActor(['platform.users.read']);

    User::factory()->create([
        'name' => 'Count Scope Active',
        'email' => 'count-scope-active@example.com',
        'status' => 'active',
    ]);
    User::factory()->create([
        'name' => 'Count Scope Inactive',
        'email' => 'count-scope-inactive@example.com',
        'status' => 'inactive',
    ]);
    User::factory()->create([
        'name' => 'Count Scope Other',
        'email' => 'count-scope-other@example.com',
        'status' => 'suspended',
    ]);
    User::factory()->create([
        'name' => 'Different User',
        'email' => 'different-user@example.com',
        'status' => 'inactive',
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/users/status-counts?q=Count Scope')
        ->assertOk()
        ->assertJsonPath('data.active', 1)
        ->assertJsonPath('data.inactive', 1)
        ->assertJsonPath('data.other', 1)
        ->assertJsonPath('data.total', 3);
});

it('filters platform users by verification state for list and status counts', function (): void {
    $actor = makePlatformUserAdminActor(['platform.users.read']);

    User::factory()->create([
        'name' => 'Verification Scope Verified',
        'email' => 'verification-scope-verified@example.com',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    User::factory()->create([
        'name' => 'Verification Scope Unverified A',
        'email' => 'verification-scope-unverified-a@example.com',
        'status' => 'inactive',
        'email_verified_at' => null,
    ]);
    User::factory()->create([
        'name' => 'Verification Scope Unverified B',
        'email' => 'verification-scope-unverified-b@example.com',
        'status' => 'active',
        'email_verified_at' => null,
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/users?q=Verification Scope&verification=unverified')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.emailVerifiedAt', null)
        ->assertJsonPath('data.1.emailVerifiedAt', null);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/users/status-counts?q=Verification Scope&verification=unverified')
        ->assertOk()
        ->assertJsonPath('data.active', 1)
        ->assertJsonPath('data.inactive', 1)
        ->assertJsonPath('data.other', 0)
        ->assertJsonPath('data.total', 2);
});

it('filters platform users by role for list and status counts', function (): void {
    $actor = makePlatformUserAdminActor(['platform.users.read']);

    $targetRole = RoleModel::query()->create([
        'code' => 'platform_user_admin_target_role',
        'name' => 'Platform User Admin Target Role',
        'status' => 'active',
        'is_system' => false,
    ]);
    $otherRole = RoleModel::query()->create([
        'code' => 'platform_user_admin_other_role',
        'name' => 'Platform User Admin Other Role',
        'status' => 'active',
        'is_system' => false,
    ]);

    $targetUserA = User::factory()->create([
        'name' => 'Role Scope User A',
        'email' => 'role-scope-user-a@example.com',
        'status' => 'active',
    ]);
    $targetUserB = User::factory()->create([
        'name' => 'Role Scope User B',
        'email' => 'role-scope-user-b@example.com',
        'status' => 'inactive',
    ]);
    $otherUser = User::factory()->create([
        'name' => 'Role Scope User C',
        'email' => 'role-scope-user-c@example.com',
        'status' => 'active',
    ]);

    $targetUserA->roles()->sync([$targetRole->id]);
    $targetUserB->roles()->sync([$targetRole->id]);
    $otherUser->roles()->sync([$otherRole->id]);

    $response = $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/users?q=Role Scope User&roleId='.$targetRole->id)
        ->assertOk();

    $returnedIds = collect($response->json('data'))->pluck('id')->all();
    expect($returnedIds)->toHaveCount(2);
    expect($returnedIds)->toContain($targetUserA->id);
    expect($returnedIds)->toContain($targetUserB->id);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/users/status-counts?q=Role Scope User&roleId='.$targetRole->id)
        ->assertOk()
        ->assertJsonPath('data.active', 1)
        ->assertJsonPath('data.inactive', 1)
        ->assertJsonPath('data.other', 0)
        ->assertJsonPath('data.total', 2);
});

it('bulk updates platform user status when authorized', function (): void {
    $actor = makePlatformUserAdminActor(['platform.users.update-status']);
    $targetA = User::factory()->create([
        'name' => 'Bulk Status User A',
        'email' => 'bulk-status-user-a@example.com',
        'status' => 'active',
    ]);
    $targetB = User::factory()->create([
        'name' => 'Bulk Status User B',
        'email' => 'bulk-status-user-b@example.com',
        'status' => 'active',
    ]);

    $response = $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/bulk-status', [
            'userIds' => [$targetA->id, $targetB->id, 999999],
            'status' => 'inactive',
            'reason' => 'Bulk lockout validation',
        ])
        ->assertOk()
        ->assertJsonPath('data.requestedCount', 3)
        ->assertJsonPath('data.updatedCount', 2)
        ->assertJsonPath('data.skippedUserIds.0', 999999);

    $updatedIds = collect($response->json('data.users'))->pluck('id')->all();
    expect($updatedIds)->toContain($targetA->id);
    expect($updatedIds)->toContain($targetB->id);

    $targetA->refresh();
    $targetB->refresh();
    expect($targetA->status)->toBe('inactive');
    expect($targetB->status)->toBe('inactive');
    expect($targetA->status_reason)->toBe('Bulk lockout validation');
    expect($targetB->status_reason)->toBe('Bulk lockout validation');

    expect(
        PlatformUserAdminAuditLogModel::query()
            ->where('action', 'platform-user.status.updated')
            ->whereIn('target_user_id', [$targetA->id, $targetB->id])
            ->count()
    )->toBe(2);
});

it('forbids bulk platform user status update without permission', function (): void {
    $actor = makePlatformUserAdminActor();
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/bulk-status', [
            'userIds' => [$target->id],
            'status' => 'inactive',
            'reason' => 'No permission',
        ])
        ->assertForbidden();
});

it('requires approval case reference when updating status for privileged users', function (): void {
    config()->set('platform_user_admin.privileged_change_controls.enabled', true);

    $actor = makePlatformUserAdminActor(['platform.users.update-status']);
    $target = makePrivilegedPlatformTargetUser();

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/'.$target->id.'/status', [
            'status' => 'inactive',
            'reason' => 'Privileged lockout check',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['approvalCaseReference']);

    $target->refresh();
    expect($target->status)->toBe('active');
});

it('rejects privileged status update without approval in bulk and keeps updates atomic', function (): void {
    config()->set('platform_user_admin.privileged_change_controls.enabled', true);

    $actor = makePlatformUserAdminActor(['platform.users.update-status']);
    $regularTarget = User::factory()->create([
        'email' => 'atomic-status-regular@example.com',
        'status' => 'active',
    ]);
    $privilegedTarget = makePrivilegedPlatformTargetUser();

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/bulk-status', [
            'userIds' => [$regularTarget->id, $privilegedTarget->id],
            'status' => 'inactive',
            'reason' => 'Bulk privileged check',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['approvalCaseReference']);

    $regularTarget->refresh();
    $privilegedTarget->refresh();
    expect($regularTarget->status)->toBe('active');
    expect($privilegedTarget->status)->toBe('active');
});

it('allows privileged status update when approval case reference is provided', function (): void {
    config()->set('platform_user_admin.privileged_change_controls.enabled', true);

    $actor = makePlatformUserAdminActor(['platform.users.update-status']);
    $target = makePrivilegedPlatformTargetUser();

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/'.$target->id.'/status', [
            'status' => 'inactive',
            'reason' => 'Approved privileged update',
            'approvalCaseReference' => 'CASE-PLT-2026-0001',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $target->refresh();
    expect($target->status)->toBe('inactive');

    $log = PlatformUserAdminAuditLogModel::query()
        ->where('target_user_id', $target->id)
        ->where('action', 'platform-user.status.updated')
        ->latest('created_at')
        ->first();

    expect($log?->metadata['approval_case_reference'] ?? null)->toBe('CASE-PLT-2026-0001');
    expect($log?->metadata['transition']['from'] ?? null)->toBe('active');
    expect($log?->metadata['transition']['to'] ?? null)->toBe('inactive');
    expect($log?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($log?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('bulk dispatches invite and reset links when authorized', function (): void {
    $actor = makePlatformUserAdminActor(['platform.users.reset-password']);
    $unverified = User::factory()->create([
        'email' => 'bulk-link-unverified@example.com',
        'email_verified_at' => null,
    ]);
    $verified = User::factory()->create([
        'email' => 'bulk-link-verified@example.com',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/users/bulk-credential-links', [
            'userIds' => [$unverified->id, $verified->id, 999999],
        ])
        ->assertOk()
        ->assertJsonPath('data.requestedCount', 3)
        ->assertJsonPath('data.dispatchedCount', 2)
        ->assertJsonPath('data.inviteCount', 1)
        ->assertJsonPath('data.resetCount', 1)
        ->assertJsonPath('data.skippedUserIds.0', 999999)
        ->assertJsonPath('data.failedCount', 0)
        ->assertJsonCount(0, 'data.failedUserIds')
        ->assertJsonCount(0, 'data.failed');

    $inviteAudit = PlatformUserAdminAuditLogModel::query()
        ->where('target_user_id', $unverified->id)
        ->where('action', 'platform-user.invite-link.sent')
        ->first();
    $resetAudit = PlatformUserAdminAuditLogModel::query()
        ->where('target_user_id', $verified->id)
        ->where('action', 'platform-user.password-reset-link.sent')
        ->first();

    expect($inviteAudit)->not->toBeNull();
    expect($resetAudit)->not->toBeNull();
});

it('continues bulk credential-link dispatch and reports failed user ids when one dispatch fails', function (): void {
    config()->set('mail.default', 'smtp');

    $actor = makePlatformUserAdminActor(['platform.users.reset-password']);
    $unverified = User::factory()->create([
        'email' => 'bulk-link-partial-unverified@example.com',
        'email_verified_at' => null,
    ]);
    $verified = User::factory()->create([
        'email' => 'bulk-link-partial-verified@example.com',
        'email_verified_at' => now(),
    ]);

    Password::shouldReceive('broker')
        ->twice()
        ->andReturnSelf();
    Password::shouldReceive('sendResetLink')
        ->twice()
        ->andReturn(Password::RESET_LINK_SENT, Password::INVALID_USER);

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/users/bulk-credential-links', [
            'userIds' => [$unverified->id, $verified->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.requestedCount', 2)
        ->assertJsonPath('data.dispatchedCount', 1)
        ->assertJsonPath('data.inviteCount', 1)
        ->assertJsonPath('data.resetCount', 0)
        ->assertJsonPath('data.failedCount', 1)
        ->assertJsonPath('data.failedUserIds.0', $verified->id)
        ->assertJsonPath('data.failed.0.userId', $verified->id);

    expect(
        PlatformUserAdminAuditLogModel::query()
            ->where('target_user_id', $unverified->id)
            ->where('action', 'platform-user.invite-link.sent')
            ->exists()
    )->toBeTrue();
    expect(
        PlatformUserAdminAuditLogModel::query()
            ->where('target_user_id', $verified->id)
            ->where('action', 'platform-user.password-reset-link.sent')
            ->exists()
    )->toBeFalse();
});

it('forbids bulk credential link dispatch without permission', function (): void {
    $actor = makePlatformUserAdminActor();
    $target = User::factory()->create([
        'email' => 'bulk-link-forbidden@example.com',
        'email_verified_at' => null,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/users/bulk-credential-links', [
            'userIds' => [$target->id],
        ])
        ->assertForbidden();
});

it('bulk assigns platform roles for selected users when authorized', function (): void {
    $actor = makePlatformUserAdminActor(['platform.rbac.manage-user-roles']);
    $targetA = User::factory()->create([
        'email' => 'bulk-role-target-a@example.com',
    ]);
    $targetB = User::factory()->create([
        'email' => 'bulk-role-target-b@example.com',
    ]);
    $roleA = RoleModel::query()->create([
        'code' => 'bulk_role_assign_a',
        'name' => 'Bulk Role Assign A',
        'status' => 'active',
        'is_system' => false,
    ]);
    $roleB = RoleModel::query()->create([
        'code' => 'bulk_role_assign_b',
        'name' => 'Bulk Role Assign B',
        'status' => 'active',
        'is_system' => false,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/bulk-roles', [
            'userIds' => [$targetA->id, $targetB->id, 999999],
            'roleIds' => [$roleA->id, $roleB->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.requestedCount', 3)
        ->assertJsonPath('data.updatedCount', 2)
        ->assertJsonPath('data.skippedUserIds.0', 999999)
        ->assertJsonCount(2, 'data.updates');

    $targetA->refresh();
    $targetB->refresh();
    $targetARoleIds = $targetA->roles()->pluck('roles.id')->all();
    $targetBRoleIds = $targetB->roles()->pluck('roles.id')->all();

    expect($targetARoleIds)->toContain($roleA->id);
    expect($targetARoleIds)->toContain($roleB->id);
    expect($targetBRoleIds)->toContain($roleA->id);
    expect($targetBRoleIds)->toContain($roleB->id);

    expect(
        PlatformRbacAuditLogModel::query()
            ->where('action', 'platform-rbac.user.roles.synced')
            ->whereIn('target_id', [(string) $targetA->id, (string) $targetB->id])
            ->count()
    )->toBe(2);
});

it('limits facility assigned user admins to hospital operational role assignment', function (): void {
    $actor = makePlatformUserAdminActor(['platform.rbac.manage-user-roles']);
    $tenant = TenantModel::query()->create([
        'code' => 'ROLELIM',
        'name' => 'Role Limit Hospital Group',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => 'ROLE-LIM',
        'name' => 'Role Limit Facility',
        'facility_type' => 'dispensary',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);
    $target = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'facility-role-target@example.com',
    ]);
    $hospitalRole = RoleModel::query()->create([
        'code' => 'ADMIN.REGISTRATION',
        'name' => 'Registration Clerk',
        'status' => 'active',
        'is_system' => true,
    ]);
    $platformRole = RoleModel::query()->create([
        'code' => 'PLATFORM.USER.ADMIN',
        'name' => 'Platform User Administrator',
        'status' => 'active',
        'is_system' => true,
    ]);

    DB::table('facility_user')->insert([
        [
            'facility_id' => $facility->id,
            'user_id' => $actor->id,
            'role' => 'facility_admin',
            'is_primary' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'facility_id' => $facility->id,
            'user_id' => $target->id,
            'role' => 'staff',
            'is_primary' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $headers = [
        'X-Tenant-Code' => 'ROLELIM',
        'X-Facility-Code' => 'ROLE-LIM',
    ];

    $this->actingAs($actor)
        ->withHeaders($headers)
        ->patchJson('/api/v1/platform/admin/users/'.$target->id.'/roles', [
            'roleIds' => [$hospitalRole->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.roleIds.0', $hospitalRole->id);

    $this->actingAs($actor)
        ->withHeaders($headers)
        ->patchJson('/api/v1/platform/admin/users/'.$target->id.'/roles', [
            'roleIds' => [$platformRole->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['roleIds']);

    expect($target->roles()->where('roles.id', $platformRole->id)->exists())->toBeFalse();
});

it('filters assignable roles in rbac list for facility scoped user admins', function (): void {
    $actor = makePlatformUserAdminActor(['platform.rbac.manage-user-roles', 'platform.rbac.read']);
    $tenant = TenantModel::query()->create([
        'code' => 'ROLELIST',
        'name' => 'Role List Hospital Group',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => 'ROLE-LIST',
        'name' => 'Role List Facility',
        'facility_type' => 'dispensary',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);
    RoleModel::query()->create([
        'code' => 'ADMIN.REGISTRATION',
        'name' => 'Registration Clerk',
        'status' => 'active',
        'is_system' => true,
    ]);
    RoleModel::query()->create([
        'code' => 'PLATFORM.USER.ADMIN',
        'name' => 'Platform User Administrator',
        'status' => 'active',
        'is_system' => true,
    ]);

    DB::table('facility_user')->insert([
        'facility_id' => $facility->id,
        'user_id' => $actor->id,
        'role' => 'facility_admin',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($actor)
        ->withHeaders([
            'X-Tenant-Code' => 'ROLELIST',
            'X-Facility-Code' => 'ROLE-LIST',
        ])
        ->getJson('/api/v1/platform/admin/roles')
        ->assertOk()
        ->assertJsonPath('meta.roleAssignmentPolicy', 'hospital_operational');

    $codes = collect($response->json('data'))->pluck('code')->map(fn ($code) => strtoupper((string) $code))->all();

    expect($codes)->toContain('ADMIN.REGISTRATION');
    expect($codes)->not->toContain('PLATFORM.USER.ADMIN');
    expect(collect($response->json('data'))->firstWhere('code', 'ADMIN.REGISTRATION'))->toMatchArray([
        'riskTier' => 'hospital',
        'isElevated' => false,
    ]);
    expect(collect($response->json('data'))->every(fn (array $role): bool => !str_starts_with(strtoupper((string) ($role['code'] ?? '')), 'PLATFORM.') && !str_contains(strtoupper((string) ($role['code'] ?? '')), 'SUPER.ADMIN')))->toBeTrue();
});

it('forbids bulk platform role assignment without permission', function (): void {
    $actor = makePlatformUserAdminActor();
    $target = User::factory()->create([
        'email' => 'bulk-role-forbidden@example.com',
    ]);
    $role = RoleModel::query()->create([
        'code' => 'bulk_role_forbidden',
        'name' => 'Bulk Role Forbidden',
        'status' => 'active',
        'is_system' => false,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/bulk-roles', [
            'userIds' => [$target->id],
            'roleIds' => [$role->id],
        ])
        ->assertForbidden();
});

it('requires approval case reference when syncing roles for privileged users', function (): void {
    config()->set('platform_user_admin.privileged_change_controls.enabled', true);

    $actor = makePlatformUserAdminActor(['platform.rbac.manage-user-roles']);
    $target = makePrivilegedPlatformTargetUser();
    $role = RoleModel::query()->create([
        'code' => 'privileged_role_sync_target',
        'name' => 'Privileged Role Sync Target',
        'status' => 'active',
        'is_system' => false,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/'.$target->id.'/roles', [
            'roleIds' => [$role->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['approvalCaseReference']);

    expect($target->roles()->count())->toBe(0);
});

it('rejects privileged role assignment without approval in bulk and keeps updates atomic', function (): void {
    config()->set('platform_user_admin.privileged_change_controls.enabled', true);

    $actor = makePlatformUserAdminActor(['platform.rbac.manage-user-roles']);
    $regularTarget = User::factory()->create(['email' => 'atomic-role-regular@example.com']);
    $privilegedTarget = makePrivilegedPlatformTargetUser();
    $role = RoleModel::query()->create([
        'code' => 'atomic_privileged_role',
        'name' => 'Atomic Privileged Role',
        'status' => 'active',
        'is_system' => false,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/bulk-roles', [
            'userIds' => [$regularTarget->id, $privilegedTarget->id],
            'roleIds' => [$role->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['approvalCaseReference']);

    expect($regularTarget->roles()->where('roles.id', $role->id)->exists())->toBeFalse();
    expect($privilegedTarget->roles()->where('roles.id', $role->id)->exists())->toBeFalse();
});

it('bulk assigns facilities for selected users when authorized', function (): void {
    $actor = makePlatformUserAdminActor(['platform.users.manage-facilities']);
    $targetA = User::factory()->create([
        'email' => 'bulk-facilities-target-a@example.com',
    ]);
    $targetB = User::factory()->create([
        'email' => 'bulk-facilities-target-b@example.com',
    ]);
    $tenant = TenantModel::query()->create([
        'code' => 'bulkfac',
        'name' => 'Bulk Facilities Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
    $facilityA = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => 'BULK-FAC-A',
        'name' => 'Bulk Facility A',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);
    $facilityB = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => 'BULK-FAC-B',
        'name' => 'Bulk Facility B',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/bulk-facilities', [
            'userIds' => [$targetA->id, $targetB->id, 999999],
            'facilityAssignments' => [
                [
                    'facilityId' => $facilityA->id,
                    'role' => 'Nurse In-charge',
                    'isPrimary' => true,
                    'isActive' => true,
                ],
                [
                    'facilityId' => $facilityB->id,
                    'role' => 'Backup Coverage',
                    'isPrimary' => false,
                    'isActive' => true,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.requestedCount', 3)
        ->assertJsonPath('data.updatedCount', 2)
        ->assertJsonPath('data.skippedUserIds.0', 999999)
        ->assertJsonCount(2, 'data.users');

    expect(DB::table('facility_user')->where('user_id', $targetA->id)->count())->toBe(2);
    expect(DB::table('facility_user')->where('user_id', $targetB->id)->count())->toBe(2);
    expect(DB::table('facility_user')
        ->where('user_id', $targetA->id)
        ->where('facility_id', $facilityA->id)
        ->where('is_primary', true)
        ->exists())->toBeTrue();
    expect(DB::table('facility_user')
        ->where('user_id', $targetB->id)
        ->where('facility_id', $facilityB->id)
        ->where('is_active', true)
        ->exists())->toBeTrue();

    expect(
        PlatformUserAdminAuditLogModel::query()
            ->where('action', 'platform-user.facilities.synced')
            ->whereIn('target_user_id', [$targetA->id, $targetB->id])
            ->count()
    )->toBe(2);
});

it('forbids bulk facility assignment without permission', function (): void {
    $actor = makePlatformUserAdminActor();
    $target = User::factory()->create([
        'email' => 'bulk-facilities-forbidden@example.com',
    ]);
    $tenant = TenantModel::query()->create([
        'code' => 'bulkff',
        'name' => 'Bulk Facilities Forbidden Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => 'BULK-FAC-F',
        'name' => 'Bulk Facility Forbidden',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/bulk-facilities', [
            'userIds' => [$target->id],
            'facilityAssignments' => [
                [
                    'facilityId' => $facility->id,
                    'role' => 'Coverage',
                    'isPrimary' => true,
                    'isActive' => true,
                ],
            ],
        ])
        ->assertForbidden();
});

it('requires approval case reference when syncing facilities for privileged users', function (): void {
    config()->set('platform_user_admin.privileged_change_controls.enabled', true);

    $actor = makePlatformUserAdminActor(['platform.users.manage-facilities']);
    $target = makePrivilegedPlatformTargetUser();
    $tenant = TenantModel::query()->create([
        'code' => 'prvfac',
        'name' => 'Privileged Facilities Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => 'PRV-FAC-01',
        'name' => 'Privileged Facility',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/'.$target->id.'/facilities', [
            'facilityAssignments' => [
                [
                    'facilityId' => $facility->id,
                    'role' => 'Coverage',
                    'isPrimary' => true,
                    'isActive' => true,
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['approvalCaseReference']);

    expect(DB::table('facility_user')->where('user_id', $target->id)->exists())->toBeFalse();
});

it('rejects privileged facility assignment without approval in bulk and keeps updates atomic', function (): void {
    config()->set('platform_user_admin.privileged_change_controls.enabled', true);

    $actor = makePlatformUserAdminActor(['platform.users.manage-facilities']);
    $regularTarget = User::factory()->create(['email' => 'atomic-facility-regular@example.com']);
    $privilegedTarget = makePrivilegedPlatformTargetUser();
    $tenant = TenantModel::query()->create([
        'code' => 'prvatm',
        'name' => 'Privileged Atomic Facilities Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => 'PRV-ATM-01',
        'name' => 'Privileged Atomic Facility',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/bulk-facilities', [
            'userIds' => [$regularTarget->id, $privilegedTarget->id],
            'facilityAssignments' => [
                [
                    'facilityId' => $facility->id,
                    'role' => 'Coverage',
                    'isPrimary' => true,
                    'isActive' => true,
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['approvalCaseReference']);

    expect(DB::table('facility_user')->where('user_id', $regularTarget->id)->exists())->toBeFalse();
    expect(DB::table('facility_user')->where('user_id', $privilegedTarget->id)->exists())->toBeFalse();
});

it('exports platform user admin audit logs as csv', function (): void {
    $actor = makePlatformUserAdminActor(['platform.users.view-audit-logs']);
    $target = User::factory()->create();

    PlatformUserAdminAuditLogModel::query()->create([
        'actor_id' => $actor->id,
        'target_user_id' => $target->id,
        'action' => 'platform-user.updated',
        'changes' => ['email' => ['before' => 'old@example.com', 'after' => 'new@example.com']],
        'metadata' => ['source' => 'feature-test'],
        'created_at' => now()->subMinute(),
    ]);

    PlatformUserAdminAuditLogModel::query()->create([
        'actor_id' => $actor->id,
        'target_user_id' => $target->id,
        'action' => 'platform-user.status.updated',
        'changes' => ['status' => ['before' => 'active', 'after' => 'inactive']],
        'metadata' => ['source' => 'feature-test'],
        'created_at' => now(),
    ]);

    $response = $this->actingAs($actor)
        ->get('/api/v1/platform/admin/users/'.$target->id.'/audit-logs/export?action=platform-user.updated');

    $response->assertOk();
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    $response->assertHeader('X-Export-System-Name', 'Afyanova AHS');
    $response->assertHeader('X-Export-System-Slug', 'afyanova_ahs');
    $csv = $response->streamedContent();
    expect((string) $response->headers->get('content-disposition'))->toContain('afyanova_ahs_platform_user_audit_');
    expect($csv)->toContain('platform-user.updated');
    expect($csv)->not->toContain('platform-user.status.updated');
});

it('updates platform user profile fields when authorized', function (): void {
    $actor = makePlatformUserAdminActor(['platform.users.update']);
    $target = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old-user@example.com',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/'.$target->id, [
            'name' => 'New Name',
            'email' => 'new-user@example.com',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.email', 'new-user@example.com');

    $target->refresh();
    expect($target->name)->toBe('New Name');
    expect($target->email)->toBe('new-user@example.com');
});

it('requires approval case reference when updating privileged platform user profile fields', function (): void {
    config()->set('platform_user_admin.privileged_change_controls.enabled', true);

    $actor = makePlatformUserAdminActor(['platform.users.update']);
    $target = makePrivilegedPlatformTargetUser();

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/'.$target->id, [
            'name' => 'Protected Rename',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['approvalCaseReference']);
});

it('allows privileged platform user profile update when approval case reference is provided', function (): void {
    config()->set('platform_user_admin.privileged_change_controls.enabled', true);

    $actor = makePlatformUserAdminActor(['platform.users.update']);
    $target = makePrivilegedPlatformTargetUser();

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/'.$target->id, [
            'name' => 'Protected Rename',
            'approvalCaseReference' => 'CASE-PLT-2026-PRF-0001',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Protected Rename');

    $target->refresh();
    expect($target->name)->toBe('Protected Rename');

    $log = PlatformUserAdminAuditLogModel::query()
        ->where('target_user_id', $target->id)
        ->where('action', 'platform-user.updated')
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull();
    expect(data_get($log?->metadata, 'approval_case_reference'))->toBe('CASE-PLT-2026-PRF-0001');
});

it('rejects lifecycle status fields on platform user profile update endpoint', function (): void {
    $actor = makePlatformUserAdminActor(['platform.users.update']);
    $target = User::factory()->create([
        'name' => 'Lifecycle Guard Target',
        'email' => 'lifecycle-guard-target@example.com',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/'.$target->id, [
            'name' => 'Should Not Persist',
            'status' => 'inactive',
            'reason' => 'Must use dedicated status endpoint',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    $target->refresh();
    expect($target->name)->toBe('Lifecycle Guard Target');
    expect($target->status)->toBe('active');
});

it('forbids platform user profile update without permission', function (): void {
    $actor = makePlatformUserAdminActor();
    $target = User::factory()->create([
        'name' => 'Forbidden Update',
        'email' => 'forbidden-update@example.com',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/'.$target->id, [
            'name' => 'Should Not Persist',
        ])
        ->assertForbidden();
});

it('rejects duplicate email when updating platform user profile', function (): void {
    $actor = makePlatformUserAdminActor(['platform.users.update']);
    $target = User::factory()->create([
        'name' => 'Target User',
        'email' => 'target-user@example.com',
    ]);
    User::factory()->create([
        'name' => 'Existing User',
        'email' => 'existing-user@example.com',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/users/'.$target->id, [
            'email' => 'existing-user@example.com',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('sends invite link for unverified platform user and writes invite audit log', function (): void {
    $actor = makePlatformUserAdminActor(['platform.users.reset-password']);
    $target = User::factory()->create([
        'email' => 'invite-user@example.com',
        'email_verified_at' => null,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/users/'.$target->id.'/invite-link')
        ->assertOk()
        ->assertJsonPath('data.userId', $target->id);

    $log = PlatformUserAdminAuditLogModel::query()
        ->where('target_user_id', $target->id)
        ->where('action', 'platform-user.invite-link.sent')
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull();
    expect($log?->actor_id)->toBe($actor->id);
});

it('returns a local preview url when invite link is generated under local log-style mail delivery', function (): void {
    config()->set('mail.default', 'log');

    $actor = makePlatformUserAdminActor(['platform.users.reset-password']);
    $target = User::factory()->create([
        'email' => 'invite-preview-user@example.com',
        'email_verified_at' => null,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/users/'.$target->id.'/invite-link')
        ->assertOk()
        ->assertJsonPath('data.userId', $target->id)
        ->assertJsonPath('data.deliveryMode', 'local-preview')
        ->assertJsonPath('data.previewUrl', fn ($value) => is_string($value) && str_contains($value, '/reset-password/'));
});

it('returns a local preview url for invite links in local environment even when smtp is configured', function (): void {
    app()->detectEnvironment(static fn (): string => 'local');
    config()->set('mail.default', 'smtp');

    $actor = makePlatformUserAdminActor(['platform.users.reset-password']);
    $target = User::factory()->create([
        'email' => 'invite-local-smtp-user@example.com',
        'email_verified_at' => null,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/users/'.$target->id.'/invite-link')
        ->assertOk()
        ->assertJsonPath('data.userId', $target->id)
        ->assertJsonPath('data.deliveryMode', 'local-preview')
        ->assertJsonPath('data.previewUrl', fn ($value) => is_string($value) && str_contains($value, '/reset-password/'));
});

it('returns a local preview url when password reset link is generated under local log-style mail delivery', function (): void {
    config()->set('mail.default', 'log');

    $actor = makePlatformUserAdminActor(['platform.users.reset-password']);
    $target = User::factory()->create([
        'email' => 'reset-preview-user@example.com',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/users/'.$target->id.'/password-reset-link')
        ->assertOk()
        ->assertJsonPath('data.userId', $target->id)
        ->assertJsonPath('data.deliveryMode', 'local-preview')
        ->assertJsonPath('data.previewUrl', fn ($value) => is_string($value) && str_contains($value, '/reset-password/'));
});

it('rejects invite link dispatch for verified platform user', function (): void {
    $actor = makePlatformUserAdminActor(['platform.users.reset-password']);
    $target = User::factory()->create([
        'email' => 'verified-user@example.com',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/users/'.$target->id.'/invite-link')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['inviteLink']);
});
