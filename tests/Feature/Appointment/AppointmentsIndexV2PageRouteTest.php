<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 6 (cutover) of reports/appointments-scheduling-workspace-
 * modernization-plan.md: /appointments now renders the rebuilt page
 * directly; /appointments/v2 stays reachable as an alias, and the
 * pre-cutover page moves to /appointments/legacy for rollback — the same
 * route-pair shape patients.page's own cutover established.
 */
it('renders the rebuilt page at /appointments', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/appointments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('appointments/IndexV2'));
});

it('forbids the appointments route without appointments.read', function (): void {
    $user = makeUserWithRole([]);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/appointments')
        ->assertForbidden();
});

it('still renders the rebuilt page at the /appointments/v2 alias', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/appointments/v2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('appointments/IndexV2'));
});

it('keeps the legacy page reachable at /appointments/legacy for rollback', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/appointments/legacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('appointments/Index'));
});
