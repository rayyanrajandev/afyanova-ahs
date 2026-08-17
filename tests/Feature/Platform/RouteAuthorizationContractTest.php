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

/**
 * Deliberate two-door splits: one controller action reached through routes with
 * different abilities, on purpose, because the audiences differ.
 *
 * Keyed by "Controller@action", value is a short reason. Anything not listed
 * here that has two doors with two locks is drift, not design.
 *
 * @var array<string, string>
 */
const INTENTIONAL_ABILITY_SPLITS = [
    // Each workspace reads the timeline under its own workspace's ability, so a
    // lab technologist needs no general patients.read to see a lab patient.
    'PatientFlowController@patientTimeline' => 'per-workspace scoping',
    // Ward staff vs platform administrators viewing the same bed registry.
    'FacilityResourceRegistryController@wardBeds' => 'ward vs platform admin',
    // Signing a note is a stronger act than changing its status.
    'EncounterController@updateStatus' => 'sign vs status change',
    // Routing options for booking vs the read-only department list.
    'AppointmentController@departmentOptions' => 'routing vs plain read',
    // The prescriber reaches the catalog to prescribe; pharmacy to dispense.
    'PharmacyOrderController@approvedMedicinesCatalog' => 'prescriber vs pharmacy',
];

it('does not let one controller action sit behind two different locks by accident', function (): void {
    /**
     * Three shipped bugs came from this exact shape, all found by hand:
     *
     *  - clinician/medical-records/{id} demanded `medical.records.update` (held
     *    by nobody) while its legacy twin used a working gate — a physician
     *    could not save a note twice.
     *  - laboratory/orders/{id}/status demanded `laboratory.orders.update-status`
     *    (held by nobody) while its twin used `lab.sample.collect` — lab staff
     *    could read their worklist but not do the work.
     *  - laboratory/orders/{id}/audit-logs quietly widened access to plain
     *    `laboratory.orders.read`, bypassing the dedicated audit permission.
     *
     * A duplicated route is allowed. Duplicating it and forgetting to keep the
     * locks in step is what this catches.
     */
    $abilitiesByAction = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/')) {
            continue;
        }

        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            continue;
        }

        $ability = null;
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                // Only the ability name; the ",id" argument is not part of the lock.
                $ability = explode(',', substr($middleware, 4))[0];
                break;
            }
        }

        $methods = array_values(array_diff($route->methods(), ['HEAD']));
        sort($methods);
        $key = class_basename(explode('@', $action)[0]).'@'.explode('@', $action)[1];

        $abilitiesByAction[$key][implode('|', $methods)][$ability ?? '(none)'][] = $uri;
    }

    $drift = [];

    foreach ($abilitiesByAction as $action => $byMethod) {
        if (array_key_exists($action, INTENTIONAL_ABILITY_SPLITS)) {
            continue;
        }

        foreach ($byMethod as $methods => $byAbility) {
            if (count($byAbility) < 2) {
                continue;
            }

            $described = [];
            foreach ($byAbility as $ability => $uris) {
                $described[] = sprintf('%s (%s)', $ability, implode(', ', $uris));
            }

            $drift[] = sprintf('%s [%s] is guarded by: %s', $action, $methods, implode(' vs ', $described));
        }
    }

    expect($drift)->toBe(
        [],
        'The same controller action is reachable through routes with different abilities. '
        .'Either make the locks match, or add the action to INTENTIONAL_ABILITY_SPLITS with a reason:'
        .PHP_EOL.'  '.implode(PHP_EOL.'  ', $drift),
    );
});

/**
 * Abilities that guard a route but which no role in config/roles.php grants —
 * the state of the system on 2026-08-17, recorded so this guard can be merged
 * green and act as a ratchet rather than a permanently-red test nobody reads.
 *
 * **This list may only shrink.** Adding to it means shipping another route
 * nobody can reach; that is what the guard exists to stop. Removing from it
 * means either granting the ability to the role that needs it, or deleting the
 * route because nothing calls it and nobody should.
 *
 * Ordered by module. Radiology and pharmacy come first because they are the
 * next two workspaces to be built, and building on an unreachable route is how
 * the laboratory workspace shipped unable to accession a specimen.
 *
 * @var array<int, string>
 */
const ABILITIES_NO_ROLE_GRANTS = [
    // radiology (0) — ratchet moved 2026-08-17.
    // `radiology.orders.update` became `imaging.order` (matching the laboratory
    // twin: whoever may place an order may amend it), and the two audit-log
    // routes asked for `radiology.orders.view-audit-logs` where roles.php grants
    // `radiology.orders.audit-logs.view` — the same words transposed.
    // billing (15)
    'billing.cash-accounts.manage',
    'billing.cash-accounts.read',
    'billing.consultation-mappings.manage',
    'billing.consultation-mappings.read',
    'billing.discounts.manage',
    'billing.discounts.read',
    'billing.payer-contracts.manage',
    'billing.payer-contracts.manage-authorization-rules',
    'billing.payer-contracts.manage-price-overrides',
    'billing.payer-contracts.read',
    'billing.payer-contracts.view-audit-logs',
    'billing.payer-contracts.view-authorization-audit-logs',
    'billing.payer-contracts.view-price-override-audit-logs',
    'billing.refunds.process',
    'billing.routing.read',
    // pos (13)
    'pos.cafeteria.create',
    'pos.cafeteria.manage-catalog',
    'pos.cafeteria.read',
    'pos.frontdesk-quick.create',
    'pos.frontdesk-quick.read',
    'pos.lab-quick.create',
    'pos.lab-quick.read',
    'pos.pharmacy-otc.create',
    'pos.pharmacy-otc.read',
    'pos.registers.manage',
    'pos.sales.refund',
    'pos.sales.void',
    'pos.sessions.manage',
    // admissions (1)
    'admissions.view-audit-logs',
    // appointments (3)
    'appointments.manage-referrals',
    'appointments.view-audit-logs',
    'appointments.view-referral-audit-logs',
    // claims (5)
    'claims.insurance.create',
    'claims.insurance.read',
    'claims.insurance.update',
    'claims.insurance.update-status',
    'claims.insurance.view-audit-logs',
    // clinical-procedure (2)
    'clinical-procedure.orders.update',
    'clinical-procedure.perform',
    // departments (2)
    'departments.update-status',
    'departments.view-audit-logs',
    // emergency (3)
    'emergency.triage.manage-transfers',
    'emergency.triage.view-audit-logs',
    'emergency.triage.view-transfer-audit-logs',
    // inpatient (3)
    'inpatient.ward.manage-discharge-checklist',
    'inpatient.ward.update-care-plan-status',
    'inpatient.ward.view-audit-logs',
    // inventory (8)
    'inventory.procurement.create-movement',
    'inventory.procurement.create-request',
    'inventory.procurement.manage-items',
    'inventory.procurement.manage-suppliers',
    'inventory.procurement.manage-warehouses',
    'inventory.procurement.reconcile-stock',
    'inventory.procurement.update-request-status',
    'inventory.procurement.view-audit-logs',
    // medical (1)
    'medical.records.update',
    // patients (4)
    'patients.export',
    'patients.import',
    'patients.insurance.view-audit-logs',
    'patients.view-audit-logs',
    // platform (9)
    'platform.clinical-catalog.manage-formulary',
    'platform.clinical-catalog.manage-lab-tests',
    'platform.clinical-catalog.manage-radiology-procedures',
    'platform.clinical-catalog.manage-theatre-procedures',
    'platform.clinical-catalog.view-audit-logs',
    'platform.feature-flag-overrides.view-audit-logs',
    'platform.resources.manage-service-points',
    'platform.resources.view-audit-logs',
    'platform.settings.manage-branding',
    // service (3)
    'service.requests.audit-logs.read',
    'service.requests.export',
    'service.requests.update-status',
    // specialties (5)
    'specialties.create',
    'specialties.read',
    'specialties.update',
    'specialties.update-status',
    'specialties.view-audit-logs',
    // staff (15)
    'staff.credentialing.manage-profile',
    'staff.credentialing.manage-registrations',
    'staff.credentialing.view-audit-logs',
    'staff.documents.update',
    'staff.documents.update-status',
    'staff.documents.view-audit-logs',
    'staff.privileges.create',
    'staff.privileges.read',
    'staff.privileges.update',
    'staff.privileges.update-status',
    'staff.privileges.view-audit-logs',
    'staff.specialties.manage',
    'staff.specialties.read',
    'staff.update-status',
    'staff.view-audit-logs',
    // theatre (1)
    'theatre.procedures.view-audit-logs',
];

it('guards every route with an ability some role can actually hold', function (): void {
    /**
     * The third question, and the one that was missing.
     *
     * The first assertion in this file asks "does this ability exist?" — is it
     * a Gate or a seeded permission. It passes for an ability that is in the
     * catalog and granted to nobody, which is exactly the state that:
     *
     *  - stopped a lab technologist accessioning a specimen
     *    (`laboratory.orders.update-status`), and
     *  - stopped a physician saving a consultation note twice
     *    (`medical.records.update`).
     *
     * Both were in the catalog. Both were held by no role. Both shipped.
     *
     * Gate-backed abilities are excluded: a Gate carries its own logic — role
     * codes, facility super-admin, resource ownership — so "which role grants
     * it" is not a question the permission tables can answer for them.
     */
    $gate = app(Gate::class);

    $grantedByAnyRole = [];
    foreach ((array) config('roles', []) as $definition) {
        foreach ((array) ($definition['permissions'] ?? []) as $permission) {
            $grantedByAnyRole[$permission] = true;
        }
    }

    $unreachable = [];

    foreach (allRegisteredRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                continue;
            }

            $ability = explode(',', substr($middleware, 4))[0];

            if ($gate->has($ability) || isset($grantedByAnyRole[$ability])) {
                continue;
            }

            $unreachable[$ability][] = $route->uri();
        }
    }

    $regressions = array_diff(array_keys($unreachable), ABILITIES_NO_ROLE_GRANTS);
    $fixed = array_diff(ABILITIES_NO_ROLE_GRANTS, array_keys($unreachable));

    $report = collect($regressions)
        ->map(fn (string $ability): string => sprintf(
            '  %s  ->  %s',
            $ability,
            implode(', ', array_unique($unreachable[$ability])),
        ))
        ->implode(PHP_EOL);

    expect(array_values($regressions))->toBe(
        [],
        'These routes are guarded by an ability that NO role in config/roles.php grants, '
        .'so every ordinary user gets a 403 that looks exactly like a legitimate denial:'
        .PHP_EOL.$report
        .PHP_EOL.'Grant the ability to the role that needs it, or delete the route.',
    );

    // The ratchet's other half: once an entry is fixed, it must leave the list,
    // or the list slowly stops describing anything.
    expect(array_values($fixed))->toBe(
        [],
        'These abilities are now reachable — remove them from ABILITIES_NO_ROLE_GRANTS: '
        .implode(', ', $fixed),
    );
});
