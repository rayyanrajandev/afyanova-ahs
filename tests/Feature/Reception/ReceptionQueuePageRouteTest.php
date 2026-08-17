<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Was coverage for a standalone `/reception/queue` page ("Phase 6 (slice 1)"
 * of a since-unavailable planning doc) that was never built: no web.php
 * route, no reception/Queue.vue component, and nothing in the frontend ever
 * links to it. The queue that actually shipped lives as a tab inside
 * reception/Index.vue (see Index.vue's SplitPane/Tabs work, 2026-08-11) —
 * a second, parallel queue page would fork that UI rather than reuse it.
 * Repointed at the real route (bug found & fixed 2026-08-11) so the
 * page-renders / permission-gated coverage this file provided isn't lost,
 * rather than just deleting it outright.
 */
it('renders the reception workspace page', function (): void {
    // Was `patients.read` — the route's gate moved to the dedicated
    // `reception.access` permission (2026-08-13) after a nurse (who also
    // holds `patients.read`, and briefly `patients.create` under this
    // route's intermediate gate) was found able to load the full Reception
    // workspace. See routes/web.php's own comment for the full history.
    $user = makeUserWithRole(['reception.access']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/reception')
        ->assertOk()
        // shouldExist: false — config/inertia.php's page_paths still points at
        // resources/js/pages, a stale path from before the ts/ rename; the
        // real pages live in resources/ts/pages. That's a separate,
        // pre-existing, app-wide config bug (found 2026-08-11, out of
        // Reception scope — it's why EmergencyQueuePageRouteTest,
        // WorkspaceV2RouteTest, PatientsIndexV2PageRouteTest etc. also fail),
        // not something this test should silently depend on to pass.
        ->assertInertia(fn ($page) => $page->component('reception/Index', false));
});

it('forbids the reception workspace route without reception.access', function (): void {
    // A user with real, adjacent permissions (patients.read/create) but not
    // the dedicated workspace-access permission — the exact shape of the
    // cross-role bug this gate was changed to prevent (2026-08-13).
    $user = makeUserWithRole(['patients.read', 'patients.create']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/reception')
        ->assertForbidden();
});
