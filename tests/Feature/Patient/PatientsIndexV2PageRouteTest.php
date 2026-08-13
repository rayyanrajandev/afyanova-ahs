<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 6 (cutover) of reports/patients-index-modernization-plan.md:
 * /patients now renders the rebuilt page directly; /patients/v2 stays
 * reachable as an alias, and the pre-cutover page moves to
 * /patients/legacy for rollback — the same route-pair shape
 * patients/{id}/chart/legacy established during the Patient Chart rebuild.
 */
it('renders the rebuilt page at /patients', function (): void {
    $user = makeUserWithRole(['patients.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/patients')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('patients/IndexV2'));
});

it('forbids the patients route without patients.read', function (): void {
    $user = makeUserWithRole([]);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/patients')
        ->assertForbidden();
});

it('still renders the rebuilt page at the /patients/v2 alias', function (): void {
    $user = makeUserWithRole(['patients.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/patients/v2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('patients/IndexV2'));
});

it('keeps the legacy page reachable at /patients/legacy for rollback', function (): void {
    $user = makeUserWithRole(['patients.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/patients/legacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('patients/Index'));
});
