<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * RBAC_Remediation_Plan.md Task 5.1: billing.insurance.read/.manage and
 * billing.payments.read gate the entire NHIF module (member verification,
 * claims, tariffs, remittances) but were never granted to any role in
 * config/roles.php — meaning the Insurance Claims Officer (FINANCE.CLAIMS)
 * could never use their own module. Now granted to insurance-officer
 * (billing.payments.read/billing.insurance.read/.manage) and accountant
 * (billing.payments.read/billing.insurance.read) in config/roles.php.
 * These tests exercise the permission gate directly rather than the config
 * role assignment (which only takes effect once `php artisan roles:sync`
 * is run against a real database), proving the permission strings
 * themselves now unlock the previously-inaccessible routes.
 */
it('allows nhif tariff import history with billing.insurance.read permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('billing.insurance.read');

    $this->actingAs($user)
        ->getJson('/api/v1/billing-nhif/tariffs/history')
        ->assertOk();
});

it('forbids nhif tariff import history without billing.insurance.read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/billing-nhif/tariffs/history')
        ->assertForbidden();
});

it('allows nhif tariff import with billing.insurance.manage permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('billing.insurance.manage');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/billing-nhif/tariffs/import', []);

    expect($response->status())->not->toBe(403);
});

it('forbids nhif tariff import without billing.insurance.manage permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('billing.insurance.read');

    $this->actingAs($user)
        ->postJson('/api/v1/billing-nhif/tariffs/import', [])
        ->assertForbidden();
});
