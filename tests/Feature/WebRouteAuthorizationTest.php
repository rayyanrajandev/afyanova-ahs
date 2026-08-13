<?php

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeVerifiedWebUser(array $permissions = []): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $role = RoleModel::query()->create([
        'code' => 'ADMIN.REGISTRATION',
        'name' => 'Registration Admin',
        'status' => 'active',
        'is_system' => false,
    ]);
    $user->roles()->syncWithoutDetaching([$role->id]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

beforeEach(function (): void {
    $this->withoutVite();
});

it('forbids staff credentialing page without staff credentialing read permission', function (): void {
    $user = makeVerifiedWebUser(['staff.read']);

    $this->actingAs($user)
        ->get('/staff-credentialing')
        ->assertForbidden();
});

it('forbids platform rbac page without platform rbac read permission', function (): void {
    $user = makeVerifiedWebUser(['platform.users.read']);

    $this->actingAs($user)
        ->get('/platform/admin/roles')
        ->assertForbidden();
});

it('allows the staff directory when the user has staff read permission', function (): void {
    $user = makeVerifiedWebUser(['staff.read']);

    $this->actingAs($user)
        ->get('/staff')
        ->assertOk();
});

it('allows the platform users page when the user has platform users read permission', function (): void {
    $user = makeVerifiedWebUser(['platform.users.read']);

    $this->actingAs($user)
        ->get('/platform/admin/users')
        ->assertOk();
});