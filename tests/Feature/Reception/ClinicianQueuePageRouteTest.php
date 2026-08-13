<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 4 of reports/appointments-scheduling-workspace-modernization-plan.md
 * — a new, standalone, clinician-scoped page, deliberately separate from
 * reception/Queue.vue and triage/Queue.vue. Same route/permission shape as
 * triage/Queue.vue's own coverage.
 */
it('renders the clinician queue page', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/clinician/queue')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('clinician/Queue'));
});

it('forbids the clinician queue route without appointments.read', function (): void {
    $user = makeUserWithRole([]);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/clinician/queue')
        ->assertForbidden();
});
