<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Post-cutover: /medical-records renders the rebuilt registry directly (no
 * config gate). /medical-records/v2 stays as a working alias, and the
 * pre-cutover page is still reachable at /medical-records/legacy for rollback.
 */
it('renders the medical records index v2 page at the canonical medical-records route', function (): void {
    $user = makeUserWithRole(['medical.records.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/medical-records')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('medical-records/IndexV2'));
});

it('renders the medical records index v2 page at the /v2 alias', function (): void {
    $user = makeUserWithRole(['medical.records.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/medical-records/v2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('medical-records/IndexV2'));
});

it('renders the pre-cutover medical records page at the legacy route', function (): void {
    $user = makeUserWithRole(['medical.records.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/medical-records/legacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('medical-records/Index'));
});
