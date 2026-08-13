<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * P0 of the Reception/Emergency/Admission/Bed-Management audit
 * follow-through: emergency/Queue.vue reached full parity with the legacy
 * page (queue, status transitions incl. admit-with-bed, case creation,
 * transfers, audit logs), so /emergency-triage now renders it too — a real
 * route swap, not an alias to a kept-around legacy file (see the "no
 * legacy patches, ever" standing directive).
 */
it('renders the emergency queue page', function (): void {
    $user = makeUserWithRole(['emergency.triage.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/emergency/queue')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('emergency/Queue'));
});

it('forbids the emergency queue route without emergency.triage.read', function (): void {
    $user = makeUserWithRole([]);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/emergency/queue')
        ->assertForbidden();
});

it('renders the rebuilt page at the cutover /emergency-triage route', function (): void {
    $user = makeUserWithRole(['emergency.triage.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/emergency-triage')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('emergency/Queue'));
});

it('forbids the /emergency-triage route without emergency.triage.read', function (): void {
    $user = makeUserWithRole([]);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/emergency-triage')
        ->assertForbidden();
});
