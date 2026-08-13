<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 4 of reports/queue-based-workflow-modernization-plan.md: a new,
 * standalone page with no predecessor, matching reception/Queue.vue's and
 * encounters/List.vue's precedent — no config gate or legacy fallback
 * applies.
 */
it('renders the patient flow board page', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/patient-flow/board')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('patient-flow/Board'));
});

it('forbids the patient flow board route without appointments.read', function (): void {
    $user = makeUserWithRole([]);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/patient-flow/board')
        ->assertForbidden();
});
