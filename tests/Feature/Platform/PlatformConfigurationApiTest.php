<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires authentication for platform country profile endpoint', function (): void {
    $this->getJson('/api/v1/platform/country-profile')
        ->assertUnauthorized();
});

it('returns active country profile', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/country-profile')
        ->assertOk()
        ->assertJsonPath('data.activeCode', 'TZ')
        ->assertJsonPath('data.profile.code', 'TZ')
        ->assertJsonPath('data.profile.currencyCode', 'TZS')
        ->assertJsonPath('data.profile.timezone', 'Africa/Dar_es_Salaam')
        ->assertJsonPath('data.profile.patientAddressing.regionLabel', 'Region')
        ->assertJsonPath('data.profile.patientLocations.0.value', 'Arusha')
        ->assertJsonPath('data.profile.patientLocations.0.districts.0', 'Arusha City')
        ->assertJsonPath('data.availableProfiles.0.code', 'TZ')
        ->assertJsonPath('data.catalogProfiles.0.code', 'TZ');
});

it('returns requested country profile by code', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/country-profile?code=KE')
        ->assertOk()
        ->assertJsonPath('data.activeCode', 'TZ')
        ->assertJsonPath('data.requestedCode', 'KE')
        ->assertJsonPath('data.profile.code', 'KE')
        ->assertJsonPath('data.profile.currencyCode', 'KES')
        ->assertJsonPath('data.profile.patientAddressing.regionLabel', 'County');
});

it('returns 404 for unknown country profile code', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/country-profile?code=XX')
        ->assertNotFound();
});

it('filters available country profiles by tenant country policy', function (): void {
    config()->set('country_profiles.active', 'TZ');
    config()->set('tenant_country_policies.tenants.EAH', [
        'includeTenantCountry' => true,
        'allowedCountryCodes' => ['TZ'],
    ]);

    $user = User::factory()->create();

    seedPlatformScopeForConfiguration(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-01',
        facilityName: 'Nairobi General',
    );

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-01',
        ])
        ->getJson('/api/v1/platform/country-profile')
        ->assertOk()
        ->assertJsonPath('data.profile.code', 'KE');

    $availableCodes = array_map(
        static fn (array $profile): string => (string) ($profile['code'] ?? ''),
        $response->json('data.availableProfiles', []),
    );

    expect($availableCodes)->toBe(['TZ', 'KE']);
});

it('returns 404 when requested country profile is outside tenant country policy', function (): void {
    config()->set('country_profiles.active', 'TZ');
    config()->set('tenant_country_policies.tenants.EAH', [
        'includeTenantCountry' => true,
        'allowedCountryCodes' => ['TZ'],
    ]);

    $user = User::factory()->create();

    seedPlatformScopeForConfiguration(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-01',
        facilityName: 'Nairobi General',
    );

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-01',
        ])
        ->getJson('/api/v1/platform/country-profile?code=UG')
        ->assertNotFound();
});

it('prefers tenant allowed country codes stored on the tenant record over config fallback', function (): void {
    config()->set('country_profiles.active', 'TZ');
    config()->set('tenant_country_policies.tenants.EAH', [
        'includeTenantCountry' => true,
        'allowedCountryCodes' => ['TZ', 'KE'],
    ]);

    $user = User::factory()->create();

    seedPlatformScopeForConfiguration(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-01',
        facilityName: 'Nairobi General',
        allowedCountryCodes: ['UG'],
    );

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-01',
        ])
        ->getJson('/api/v1/platform/country-profile?code=UG')
        ->assertOk()
        ->assertJsonPath('data.profile.code', 'UG');

    $availableCodes = array_map(
        static fn (array $profile): string => (string) ($profile['code'] ?? ''),
        $response->json('data.availableProfiles', []),
    );

    expect($availableCodes)->toBe(['UG']);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-01',
        ])
        ->getJson('/api/v1/platform/country-profile?code=TZ')
        ->assertNotFound();
});

it('requires authentication for feature flags endpoint', function (): void {
    $this->getJson('/api/v1/platform/feature-flags')
        ->assertUnauthorized();
});

it('requires authentication for interoperability adapter envelope endpoint', function (): void {
    $this->getJson('/api/v1/platform/interoperability/adapter-envelope')
        ->assertUnauthorized();
});

it('returns configured feature flags', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/feature-flags')
        ->assertOk()
        ->assertJsonPath('meta.total', 9)
        ->assertJsonPath('data.0.name', 'billing.multi_currency');
});

it('filters feature flags by prefix and enabled status', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/platform/feature-flags?prefix=platform.&enabledOnly=true')
        ->assertOk()
        ->assertJsonPath('meta.prefix', 'platform.')
        ->assertJsonPath('meta.enabledOnly', true);

    $flagNames = array_map(static fn (array $item): string => $item['name'], $response->json('data'));

    expect($flagNames)->toContain('platform.country_profile.enforced');
    expect($flagNames)->not->toContain('platform.multi_tenant_isolation');
});

it('filters feature flags by prefix case-insensitively and trims input', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/platform/feature-flags?prefix=%20PLATFORM.%20')
        ->assertOk()
        ->assertJsonPath('meta.prefix', 'PLATFORM.');

    $flagNames = array_map(static fn (array $item): string => $item['name'], $response->json('data'));

    expect($flagNames)->toContain('platform.country_profile.enforced');
    expect($flagNames)->toContain('platform.multi_tenant_isolation');
    expect($flagNames)->not->toContain('billing.multi_currency');
});

it('returns interoperability adapter envelope baseline for default version', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/interoperability/adapter-envelope')
        ->assertOk()
        ->assertJsonPath('data.version', 'v1')
        ->assertJsonPath('data.eventTypePattern', 'resource.event')
        ->assertJsonPath('data.envelope.version', 'v1')
        ->assertJsonPath('data.envelope.eventId', 'uuid')
        ->assertJsonPath('data.priorityFlows.0.key', 'patient.demographics.identifiers');
});

it('normalizes interoperability adapter envelope version input', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/interoperability/adapter-envelope?version=%20V1%20')
        ->assertOk()
        ->assertJsonPath('data.version', 'v1');
});

it('returns 404 for unsupported interoperability adapter envelope version', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/interoperability/adapter-envelope?version=v9')
        ->assertNotFound();
});

/**
 * @return array{0:string,1:string}
 */
function seedPlatformScopeForConfiguration(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
    ?array $allowedCountryCodes = null,
): array {
    [$tenantId, $facilityId] = seedTenantAndFacilityForConfiguration(
        tenantCode: $tenantCode,
        tenantName: $tenantName,
        countryCode: $countryCode,
        facilityCode: $facilityCode,
        facilityName: $facilityName,
        allowedCountryCodes: $allowedCountryCodes,
    );

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'clinician',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}

/**
 * @return array{0:string,1:string}
 */
function seedTenantAndFacilityForConfiguration(
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
    ?array $allowedCountryCodes = null,
): array {
    $tenantId = (string) Str::uuid();
    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => $tenantCode,
        'name' => $tenantName,
        'country_code' => $countryCode,
        'allowed_country_codes' => $allowedCountryCodes !== null
            ? json_encode(array_values($allowedCountryCodes), JSON_THROW_ON_ERROR)
            : null,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $facilityId = (string) Str::uuid();
    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => $facilityCode,
        'name' => $facilityName,
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}
