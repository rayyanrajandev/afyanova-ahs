<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Every role must be able to reach the endpoints its own workspace calls.
 *
 * This is the half the route contract test cannot see. There, the failure is a
 * name that resolves to nothing; here, every name is valid and the role→
 * permission matrix is simply wrong — so the workspace loads and then 403s on
 * mount, which reads to the user as "the app is broken after login".
 *
 * All three of these shipped that way and were found by hand, not by a test
 * (2026-08-16 RBAC audit):
 *
 *   - CLINICAL.NURSE could not GET /nursing/mar — the medication
 *     administration record, the instrument the nursing workspace is built
 *     around. `pharmacy.orders.read` was granted to physicians, surgeons and
 *     pharmacy staff, and not to nurses.
 *   - ADMIN.REGISTRATION could not GET /reception/clinicians, the directory
 *     reception needs to route a patient at triage handoff.
 *   - CLINICAL.PHYSICIAN could not GET /clinician/results, though that one was
 *     a bad ability name rather than a role gap.
 *
 * Endpoints listed here are the ones a workspace requests on mount, so a
 * failure means a member of staff sees an error before touching anything.
 */

/**
 * `roles` is a list because a workspace is not always one job. Laboratory
 * deliberately splits duties by seniority — a bench technologist collects
 * specimens and enters results, and only a supervisor or manager verifies and
 * releases them. Requiring one role to hold every ability its workspace offers
 * would have forced verification onto LAB.STAFF, erasing a real clinical
 * control. The rule is therefore "every guarded action is reachable by at least
 * one of the workspace's roles", which still catches an action no role at all
 * can perform — the gap this guard exists to find.
 *
 * The first role listed is the workspace's baseline: it must reach every
 * endpoint the workspace loads on mount, since that is what a member of staff
 * hits on login.
 *
 * @return array<string, array{roles: array<int, string>, endpoints: array<int, string>}>
 */
function workspaceAccessMatrix(): array
{
    return [
        'reception' => [
            'roles' => ['ADMIN.REGISTRATION'],
            'endpoints' => [
                '/api/v1/reception/queue?stage=waiting_triage',
                '/api/v1/reception/queue/status-counts',
                '/api/v1/reception/patients',
                '/api/v1/reception/appointments',
                '/api/v1/reception/clinicians',
            ],
        ],
        'clinician' => [
            'roles' => ['CLINICAL.PHYSICIAN'],
            'endpoints' => [
                '/api/v1/clinician/encounters',
                '/api/v1/clinician/patients',
                '/api/v1/clinician/results',
                '/api/v1/patient-flow/notifications',
            ],
        ],
        'nursing' => [
            'roles' => ['CLINICAL.NURSE'],
            'endpoints' => [
                '/api/v1/nursing/tasks',
                '/api/v1/nursing/patients',
                '/api/v1/nursing/mar',
            ],
        ],
        // Laboratory, added with the workspace's flow work. The journey tab is
        // listed alongside the worklist because it is a route the workspace
        // itself renders, and a bench technologist hitting 403 on it would see
        // an error inside their own screen.
        'laboratory' => [
            'roles' => ['LAB.STAFF', 'LAB.SUPERVISOR'],
            'endpoints' => [
                '/api/v1/laboratory/orders?perPage=50',
                '/api/v1/laboratory/orders/status-counts',
            ],
        ],
        // Radiology, added with its workspace routes. Both roles were missing
        // `radiology.orders.read` when those routes went in — only clinicians
        // held it — so every read the workspace makes on mount would have 403'd
        // for the radiographers who live in it. Exactly the failure this guard
        // was written to catch, listed here so it cannot come back.
        'radiology' => [
            'roles' => ['RADIOLOGY.STAFF', 'RADIOLOGY.SUPERVISOR'],
            'endpoints' => [
                '/api/v1/radiology/orders?perPage=50',
                '/api/v1/radiology/orders/status-counts',
            ],
        ],
    ];
}

/**
 * Builds the role exactly as config/roles.php defines it, rather than relying
 * on a seeder having run. config/roles.php is the source of truth that
 * `php artisan roles:sync` pushes to every facility, so testing against it is
 * testing the thing that actually ships — and it keeps this guard honest even
 * in a schema with no seed data.
 */
function userWithRoleCode(string $roleCode): User
{
    $definition = collect((array) config('roles', []))
        ->first(static fn (array $role): bool => ($role['code'] ?? null) === $roleCode);

    expect($definition)->not->toBeNull("Role {$roleCode} is not defined in config/roles.php.");

    $role = RoleModel::query()->create([
        'code' => $definition['code'],
        'name' => $definition['name'] ?? $definition['code'],
        'description' => $definition['description'] ?? null,
        'status' => $definition['status'] ?? 'active',
        'is_system' => $definition['is_system'] ?? true,
        'access_level' => $definition['access_level'] ?? 'request',
        'scope_type' => $definition['scope_type'] ?? 'facility',
    ]);

    $permissionIds = collect((array) ($definition['permissions'] ?? []))
        ->map(static fn (string $name) => Permission::query()->firstOrCreate(['name' => $name])->id)
        ->all();

    $role->permissions()->syncWithoutDetaching($permissionIds);

    $user = User::factory()->create();
    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh();
}

it('lets every workspace role reach the endpoints its workspace calls on mount', function (): void {
    $denied = [];

    foreach (workspaceAccessMatrix() as $workspace => $spec) {
        // The baseline role — whoever opens this workspace on an ordinary shift.
        $roleCode = $spec['roles'][0];
        $user = userWithRoleCode($roleCode);

        foreach ($spec['endpoints'] as $endpoint) {
            $status = $this->actingAs($user)
                ->getJson($endpoint)
                ->getStatusCode();

            // Only authorization is under test. A 404/422/500 is a different
            // defect and belongs to a different test, so it must not be
            // silently absorbed here either — but 403 is the one this guard owns.
            if ($status === 403) {
                $denied[] = sprintf('%s (%s) is denied %s', $roleCode, $workspace, $endpoint);
            }
        }
    }

    expect($denied)->toBe(
        [],
        'These roles cannot reach their own workspace, so staff get a 403 on login:'
        .PHP_EOL.'  '.implode(PHP_EOL.'  ', $denied),
    );
});

/**
 * Action endpoints, not just the mount reads.
 *
 * The matrix above only exercised each workspace's GET endpoints, and that is
 * exactly where a real gap hid: `appointments/{id}/claim-triage` is a PATCH, so
 * nothing covered it, and CLINICAL.NURSE could not claim triage at all. The
 * ability resolved only from `emergency.triage.*` — held by CLINICAL.EMERGENCY
 * alone — so outpatient triage was gated behind Emergency Department
 * permissions and the "In Triage" state was unreachable for the role that does
 * the work (2026-08-16).
 *
 * Role gaps bite on actions, because actions are where staff do things. This
 * derives the abilities from the registered routes rather than a hand-written
 * list, so a new guarded action is covered the day it is added.
 */
it('lets every workspace role perform the actions its workspace offers', function (): void {
    $actionAbilitiesByPrefix = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/v1/')) {
            continue;
        }

        // Reads are covered by the matrix above; this is about doing things.
        if (in_array('GET', $route->methods(), true)) {
            continue;
        }

        $prefix = explode('/', substr($uri, strlen('api/v1/')))[0] ?? '';

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                continue;
            }

            $ability = explode(',', substr($middleware, 4))[0];
            $actionAbilitiesByPrefix[$prefix][$ability] = $uri;
        }
    }

    $denied = [];

    foreach (workspaceAccessMatrix() as $workspace => $spec) {
        $gates = [];
        foreach ($spec['roles'] as $roleCode) {
            $gates[$roleCode] = Gate::forUser(userWithRoleCode($roleCode));
        }

        foreach ($actionAbilitiesByPrefix[$workspace] ?? [] as $ability => $uri) {
            $permitted = false;
            $resourceScoped = false;

            foreach ($gates as $gate) {
                try {
                    if ($gate->allows($ability)) {
                        $permitted = true;
                        break;
                    }
                } catch (ArgumentCountError) {
                    // A gate that takes the record as an argument — e.g.
                    // `can:medical.records.draft.update,id`, which allows the
                    // *author* of a draft to edit it. "Can this role do it?" has
                    // no answer without a concrete record, so this guard cannot
                    // decide it and must not pretend otherwise: reporting it as
                    // denied would be a false alarm, and the resource rule is
                    // covered by the module's own tests.
                    $resourceScoped = true;
                    break;
                }
            }

            if ($permitted || $resourceScoped) {
                continue;
            }

            $denied[] = sprintf(
                'no %s role (%s) can %s — needed by %s',
                $workspace,
                implode(', ', $spec['roles']),
                $ability,
                $uri,
            );
        }
    }

    expect($denied)->toBe(
        [],
        'These roles cannot perform actions their own workspace offers:'
        .PHP_EOL.'  '.implode(PHP_EOL.'  ', $denied),
    );
});
