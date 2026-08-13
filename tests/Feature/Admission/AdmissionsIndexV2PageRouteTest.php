<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlementAny;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Admission V2 + real bed assignment plan, Phase 5 (cutover): /admissions
 * renders the rebuilt page directly; /admissions/v2 stays reachable as an
 * alias. AdmF of the Admission V2 full-parity plan deleted the pre-cutover
 * page and its /admissions/legacy route outright, per the "no legacy
 * patches, ever" standing directive (same treatment as Emergency's P0e).
 */
it('renders the rebuilt page at /admissions', function (): void {
    $user = makeUserWithRole(['admissions.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlementAny::class,
    ]);

    $this->actingAs($user)
        ->get('/admissions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admissions/IndexV2'));
});

it('forbids the admissions route without admissions.read', function (): void {
    $user = makeUserWithRole([]);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlementAny::class,
    ]);

    $this->actingAs($user)
        ->get('/admissions')
        ->assertForbidden();
});

it('still renders the rebuilt page at the /admissions/v2 alias', function (): void {
    $user = makeUserWithRole(['admissions.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlementAny::class,
    ]);

    $this->actingAs($user)
        ->get('/admissions/v2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admissions/IndexV2'));
});

it('no longer serves the deleted legacy page at /admissions/legacy', function (): void {
    $user = makeUserWithRole(['admissions.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlementAny::class,
    ]);

    $this->actingAs($user)
        ->get('/admissions/legacy')
        ->assertNotFound();
});
