<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Post-cutover: /encounters/{id} renders the rebuilt workspace directly (no
 * config gate). /encounters/{id}/v2 stays as a working alias. The
 * pre-cutover page (encounters/Show.vue + encounters/Workspace.vue) reached
 * full parity and was deleted outright — no /legacy rollback route kept.
 */
it('renders the v2 workspace page at the canonical encounters/{id} route', function (): void {
    $user = makeUserWithRole(['medical.records.read', 'medical.records.create']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $encounterId = (string) Str::uuid();

    $this->actingAs($user)
        ->get('/encounters/'.$encounterId)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('encounters/WorkspaceV2')
            ->where('encounterId', $encounterId));
});

it('renders the v2 workspace page at the /v2 alias', function (): void {
    $user = makeUserWithRole(['medical.records.read', 'medical.records.create']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $encounterId = (string) Str::uuid();

    $this->actingAs($user)
        ->get('/encounters/'.$encounterId.'/v2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('encounters/WorkspaceV2')
            ->where('encounterId', $encounterId));
});

it('no longer serves the deleted pre-cutover page at the legacy route', function (): void {
    $user = makeUserWithRole(['medical.records.read', 'medical.records.create']);

    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $encounterId = (string) Str::uuid();

    $this->actingAs($user)
        ->get('/encounters/'.$encounterId.'/legacy')
        ->assertNotFound();
});
