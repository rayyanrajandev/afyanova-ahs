<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| The radiology workspace opens for the people who work in it.
|--------------------------------------------------------------------------
|
| routes/web.php has redirected RADIOLOGY.* logins to /radiology since the
| backend landed, but the Inertia page `radiology/Index` did not exist — so the
| redirect led somewhere blank. This is the same shape as the laboratory
| workspace shipping unable to accession a specimen: everything around the
| workspace was right, and the workspace itself was not reachable.
|
| Roles are built from config/roles.php, the file `roles:sync` ships, rather
| than hand-granted permissions — a test user in a state no real login can be in
| proves nothing.
*/

uses(RefreshDatabase::class);

function radiologyWorkspaceUser(string $roleCode): User
{
    $definition = collect((array) config('roles', []))
        ->first(static fn (array $role): bool => ($role['code'] ?? null) === $roleCode);

    expect($definition)->not->toBeNull("Role {$roleCode} is not defined in config/roles.php.");

    $role = RoleModel::query()->create([
        'code' => $definition['code'],
        'name' => $definition['name'] ?? $definition['code'],
        'status' => 'active',
        'is_system' => true,
        'access_level' => $definition['access_level'] ?? 'request',
        'scope_type' => $definition['scope_type'] ?? 'facility',
    ]);

    $permissionIds = collect((array) ($definition['permissions'] ?? []))
        ->map(static fn (string $name) => Permission::query()->firstOrCreate(['name' => $name])->id)
        ->all();
    $role->permissions()->syncWithoutDetaching($permissionIds);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh();
}

it('opens the radiology workspace for a radiographer', function (): void {
    $this->actingAs(radiologyWorkspaceUser('RADIOLOGY.STAFF'))
        ->get('/radiology')
        ->assertOk();
});

it('opens the radiology workspace for a supervisor', function (): void {
    $this->actingAs(radiologyWorkspaceUser('RADIOLOGY.SUPERVISOR'))
        ->get('/radiology')
        ->assertOk();
});

it('renders the radiology Index page component', function (): void {
    // Guards the page's existence specifically: the route resolving is not the
    // same as the component being there, and it was the component that was
    // missing.
    $this->actingAs(radiologyWorkspaceUser('RADIOLOGY.STAFF'))
        ->get('/radiology')
        ->assertInertia(fn ($page) => $page->component('radiology/Index'));
});

it('keeps the radiology workspace closed to staff without imaging access', function (): void {
    $this->actingAs(radiologyWorkspaceUser('ADMIN.REGISTRATION'))
        ->get('/radiology')
        ->assertForbidden();
});
