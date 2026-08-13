<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The direct service queue was replaced by /nurse-queue. The old route
 * now permanently redirects.
 */
it('redirects /direct-service/queue to /nurse-queue', function (): void {
    $this->get('/direct-service/queue')
        ->assertRedirect('/nurse-queue');
});

it('keeps the legacy page reachable at /walk-in-service-requests, now marked legacy', function (): void {
    $user = makeUserWithRole(['service.requests.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/walk-in-service-requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('walk-in-service-requests/Index'));
});
