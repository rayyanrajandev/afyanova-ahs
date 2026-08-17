<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * The contract between routes and the authorization catalog.
 *
 * Both of these failures are invisible at runtime, which is the whole reason
 * they need a build-time guard:
 *
 *  - A `can:` naming an ability that is neither a defined Gate nor a seeded
 *    permission denies EVERY user, forever, with the message
 *    "This action is unauthorized." — byte-identical to a legitimate denial.
 *    Nothing in a log or a response distinguishes the two. `lab.results.read`
 *    and `pharmacy.orders.administer` both shipped this way and were only
 *    found when a user reported a 403 on login (2026-08-16 RBAC audit); the
 *    `appointment.check-in` naming drift was found the same way days earlier.
 *
 *  - A route pointing at a controller method that does not exist throws
 *    "Call to undefined method" — a 500 on first real call. This codebase has
 *    already been bitten twice (`NurseQueueController::complete`, removed
 *    2026-08-13; `LaboratoryOrderController::acknowledge` and
 *    `PharmacyOrderController::administer`, removed 2026-08-16).
 *
 * Both assertions are cheap and total: they walk every registered route once.
 */

/**
 * @return array<int, \Illuminate\Routing\Route>
 */
function allRegisteredRoutes(): array
{
    return array_values(Route::getRoutes()->getRoutes());
}

it('guards every route with an ability that actually resolves', function (): void {
    $gate = app(Gate::class);
    $permissions = DB::table('permissions')->pluck('name')->flip();

    $unresolvable = [];

    foreach (allRegisteredRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                continue;
            }

            // `can:ability,model` — only the ability is checked here.
            $ability = explode(',', substr($middleware, 4))[0];

            if ($gate->has($ability) || $permissions->has($ability)) {
                continue;
            }

            $unresolvable[$ability][] = $route->uri();
        }
    }

    $report = collect($unresolvable)
        ->map(fn (array $uris, string $ability): string => sprintf(
            '  %s  ->  %s',
            $ability,
            implode(', ', array_unique($uris)),
        ))
        ->implode(PHP_EOL);

    expect($unresolvable)->toBe(
        [],
        "These routes are guarded by an ability that is neither a defined Gate nor a seeded permission, "
        ."so they deny every user with an ordinary-looking 403:".PHP_EOL.$report,
    );
});

it('points every route at a controller action that exists', function (): void {
    $missing = [];

    foreach (allRegisteredRoutes() as $route) {
        $action = $route->getAction('uses');

        // Closure routes have nothing to resolve.
        if (! is_string($action) || ! str_contains($action, '@')) {
            continue;
        }

        [$controller, $method] = explode('@', $action, 2);

        if (! class_exists($controller)) {
            $missing[] = sprintf('%s  ->  %s (class not found)', $route->uri(), $controller);

            continue;
        }

        if (! method_exists($controller, $method)) {
            $missing[] = sprintf('%s  ->  %s::%s()', $route->uri(), class_basename($controller), $method);
        }
    }

    expect($missing)->toBe(
        [],
        'These routes point at controller actions that do not exist and will 500 on first call:'
        .PHP_EOL.'  '.implode(PHP_EOL.'  ', $missing),
    );
});

/**
 * Pre-existing drift, frozen 2026-08-16 so this guard can be green and start
 * catching NEW drift immediately rather than waiting on an unrelated cleanup.
 *
 * These names are granted by inventory roles but exist in no catalog — verified
 * absent from the production Postgres database, not just the test schema. They
 * are inert rather than harmful: no route's `can:` requires any of them (the
 * first test in this file proves that), so today they are dead grants, and the
 * roles simply promise department-scoped inventory access that was never built.
 *
 * Shrink this list; never add to it. A new entry means a role is promising
 * access that will silently never work.
 */
const KNOWN_UNSEEDED_ROLE_PERMISSIONS = [
    'inventory.view-department-items',
    'inventory.view-warehouse-own-department',
    'inventory.execute-warehouse-transfer-own-department',
    'inventory.create-requisition-cross-department',
    'inventory.manage-warehouse-own-department',
    'inventory.authorize-warehouse-transfer-receiving-department',
    'inventory.dispose-items-own-department',
];

it('keeps every permission a role grants present in the catalog', function (): void {
    // The mirror of the first test: a role granting a permission that was never
    // seeded is a silent no-op, so the role looks configured while the access it
    // promises never materialises.
    $permissions = DB::table('permissions')->pluck('name')->flip();

    $unknown = [];

    foreach ((array) config('roles', []) as $key => $definition) {
        foreach ((array) ($definition['permissions'] ?? []) as $permission) {
            if ($permissions->has($permission) || in_array($permission, KNOWN_UNSEEDED_ROLE_PERMISSIONS, true)) {
                continue;
            }

            $unknown[] = sprintf('%s grants unknown permission "%s"', $definition['code'] ?? $key, $permission);
        }
    }

    expect($unknown)->toBe(
        [],
        'config/roles.php grants permissions that are not in the catalog:'
        .PHP_EOL.'  '.implode(PHP_EOL.'  ', $unknown),
    );
});
