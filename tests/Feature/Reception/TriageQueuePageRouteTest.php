<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 3 (corrected) of
 * reports/appointments-scheduling-workspace-modernization-plan.md — a new,
 * standalone, nurse-scoped page, deliberately separate from
 * reception/Queue.vue. See the plan's Phase 3 correction note.
 */
it('renders the triage queue page', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/triage/queue')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('triage/Queue'));
});

it('forbids the triage queue route without appointments.read', function (): void {
    $user = makeUserWithRole([]);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $this->actingAs($user)
        ->get('/triage/queue')
        ->assertForbidden();
});
