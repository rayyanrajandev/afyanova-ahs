<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Reception's own patient endpoints.
|--------------------------------------------------------------------------
|
| Standing project rule: workspace frontend code calls `/reception/*` routes,
| never the generic ones, even when they share a controller. Registration was
| still reaching into `/api/v1/patients/duplicate-check` mid-flow, so the
| reception twin was added (2026-08-17) and the caller repointed.
|
| These tests exist so the twin cannot silently drift from the route it copies —
| the exact failure that left the laboratory workspace's own actions gated on an
| ability nobody held.
*/

uses(RefreshDatabase::class);

function receptionScopedUser(): User
{
    $user = User::factory()->create();

    foreach (['patients.read', 'patients.create'] as $permission) {
        Permission::query()->firstOrCreate(['name' => $permission]);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function receptionScopedPatientPayload(array $overrides = []): array
{
    return array_merge([
        'firstName' => 'Amina',
        'lastName' => 'Moshi',
        'gender' => 'female',
        'dateOfBirth' => '1996-04-21',
        'phone' => '+255700000009',
        'countryCode' => 'TZ',
        'addressLine' => 'Msasani',
    ], $overrides);
}

it('reports no duplicates through the reception route', function (): void {
    $user = receptionScopedUser();

    $this->actingAs($user)
        ->postJson('/api/v1/reception/patients/duplicate-check', [
            'firstName' => 'Nobody',
            'lastName' => 'Matches',
        ])
        ->assertOk()
        ->assertJsonPath('data.severity', 'none')
        ->assertJsonPath('data.duplicates', []);

    // A duplicate *check* must never create anything — the whole point of the
    // endpoint is that it runs before the registration commits.
    expect(PatientModel::query()->count())->toBe(0);
});

it('detects a duplicate through the reception route', function (): void {
    $user = receptionScopedUser();

    PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Amina',
        'last_name' => 'Moshi',
        'gender' => 'female',
        'date_of_birth' => '1996-04-21',
        'phone' => '+255700000009',
        'country_code' => 'TZ',
        'address_line' => 'Msasani',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients/duplicate-check', receptionScopedPatientPayload())
        ->assertOk();

    expect($response->json('data.severity'))->not->toBe('none');
});

it('gives the reception twin the same answer as the route it copies', function (): void {
    $user = receptionScopedUser();

    $payload = ['firstName' => 'Nobody', 'lastName' => 'Matches'];

    $viaReception = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients/duplicate-check', $payload)
        ->assertOk()
        ->json('data');

    $viaLegacy = $this->actingAs($user)
        ->postJson('/api/v1/patients/duplicate-check', $payload)
        ->assertOk()
        ->json('data');

    // Two doors, one room. If these ever disagree, one of them was changed
    // without the other.
    expect($viaReception)->toEqual($viaLegacy);
});

it('refuses the reception duplicate check without the registration permission', function (): void {
    $readOnly = User::factory()->create();
    Permission::query()->firstOrCreate(['name' => 'patients.read']);
    $readOnly->givePermissionTo('patients.read');

    // Same lock as the legacy route: patients.create, not patients.read.
    $this->actingAs($readOnly)
        ->postJson('/api/v1/reception/patients/duplicate-check', [
            'firstName' => 'Nobody',
            'lastName' => 'Matches',
        ])
        ->assertForbidden();
});
