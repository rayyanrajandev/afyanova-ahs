<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Post-cutover: /encounters always renders the list — there was never a
 * prior page at this route, so no config gate or legacy fallback applies.
 */
it('renders the encounters list page', function (): void {
    $user = makeUserWithRole(['medical.records.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/encounters')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('encounters/List'));
});

it('forbids the encounters list route without medical.records.read', function (): void {
    $user = makeUserWithRole([]);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/encounters')
        ->assertForbidden();
});
