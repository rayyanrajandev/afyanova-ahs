<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Mirrors ReceptionQueuePageRouteTest's coverage for the /reception route —
 * the /nursing route had no test coverage at all until now, which is how a
 * receptionist (holding `service.requests.read`, then `inpatient.ward.read`
 * under this route's two earlier gates) went unnoticed loading the full
 * Nursing workspace (found 2026-08-13, see routes/web.php's own comment for
 * the full history and Volume 3.8 §6 #6/#7).
 */
it('renders the nursing workspace page', function (): void {
    $user = makeUserWithRole(['nursing.access']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/nursing')
        ->assertOk()
        // shouldExist: false — see ReceptionQueuePageRouteTest's identical
        // note: config/inertia.php's page_paths is stale (resources/js vs
        // resources/ts), an app-wide pre-existing gap, not nursing-specific.
        ->assertInertia(fn ($page) => $page->component('nursing/Index', false));
});

it('forbids the nursing workspace route without nursing.access', function (): void {
    // A user with real, adjacent permissions (the receptionist role's own
    // service.requests.read/create) but not the dedicated workspace-access
    // permission — the exact shape of the cross-role bug this gate exists
    // to prevent.
    $user = makeUserWithRole(['service.requests.read', 'service.requests.create']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/nursing')
        ->assertForbidden();
});
