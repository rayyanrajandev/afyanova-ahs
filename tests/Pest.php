<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| Feature tests get a real database and the HTTP kernel. Subscription
| entitlement middleware is bypassed by default: it gates on facility plan
| records that are orthogonal to almost every test's subject, and leaving it
| on made unrelated failures look like authorization bugs. A test that is
| actually about entitlement re-enables it explicitly.
|
| Unit tests bind nothing — they must stay free of the framework and the
| database, which is what keeps the money arithmetic fast to run and honest.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        $this->withoutMiddleware([
            EnsureMappedFacilitySubscriptionEntitlement::class,
            EnsureFacilitySubscriptionEntitlement::class,
        ]);
    })
    ->in('Feature');

/*
 * Integration tests get the framework and a real, committed database, but no
 * wrapping transaction: they fork processes and open independent connections,
 * which cannot see work a parent has not committed. Each one is responsible
 * for cleaning up what it writes.
 */
pest()->extend(TestCase::class)->in('Integration');

/*
|--------------------------------------------------------------------------
| Shared helpers
|--------------------------------------------------------------------------
|
| Kept deliberately small. The previous Pest.php carried fixture builders for
| inventory, clinical consumption recipes and Phase 5 readiness documents —
| all of them written for tests removed in 418bc7d, several referencing
| config keys and a documents/ tree that no longer exist. Helpers earn their
| place here by being used across suites, not by having once been used.
|
*/

/**
 * A minimal active role, for tests that need a user to hold permissions.
 */
function createTestRole(string $code = 'TEST-ROLE', string $name = 'Test Role'): RoleModel
{
    /** @var RoleModel $role */
    $role = RoleModel::query()->create([
        'id' => (string) Str::uuid(),
        'code' => $code,
        'name' => $name,
        'status' => 'active',
        'effective_from' => now(),
    ]);

    return $role;
}

/**
 * A verified user holding an active role and the given permissions.
 *
 * @param  array<int, string>  $permissions
 */
function makeUserWithRole(array $permissions = [], string $roleCode = 'TEST-ROLE'): User
{
    $role = createTestRole($roleCode);

    /** @var User $user */
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $user->roles()->attach($role->id);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}
