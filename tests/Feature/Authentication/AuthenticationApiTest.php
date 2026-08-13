<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires authentication for auth me endpoint', function (): void {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('requires authentication for auth me permissions endpoint', function (): void {
    $this->getJson('/api/v1/auth/me/permissions')
        ->assertUnauthorized();
});

it('requires authentication for auth me security status endpoint', function (): void {
    $this->getJson('/api/v1/auth/me/security-status')
        ->assertUnauthorized();
});

it('returns authenticated user profile', function (): void {
    $user = User::factory()->create([
        'name' => 'Amina Admin',
        'email' => 'amina.auth@example.test',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', 'Amina Admin')
        ->assertJsonPath('data.email', 'amina.auth@example.test');
});

it('returns authenticated user permissions sorted deterministically', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('staff.view-audit-logs');
    $user->givePermissionTo('patients.view-audit-logs');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/auth/me/permissions')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $permissionNames = array_map(static fn (array $item): string => $item['name'], $response->json('data'));

    expect($permissionNames)->toBe([
        'patients.view-audit-logs',
        'staff.view-audit-logs',
    ]);
});

it('returns authenticated user security status', function (): void {
    $user = User::factory()->create();
    $user->forceFill([
        'email_verified_at' => now(),
        'two_factor_secret' => 'secret-value',
        'two_factor_recovery_codes' => json_encode(['code-1']),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user)
        ->getJson('/api/v1/auth/me/security-status')
        ->assertOk()
        ->assertJsonPath('data.emailVerified', true)
        ->assertJsonPath('data.twoFactorEnabled', true)
        ->assertJsonPath('data.twoFactorConfirmed', true);
});

it('returns two-factor disabled when recovery codes are missing', function (): void {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => 'secret-value',
        'two_factor_recovery_codes' => '[]',
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user)
        ->getJson('/api/v1/auth/me/security-status')
        ->assertOk()
        ->assertJsonPath('data.twoFactorEnabled', false)
        ->assertJsonPath('data.twoFactorConfirmed', false);
});
