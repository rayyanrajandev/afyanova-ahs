<?php

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\FeatureFlagOverrideAuditLogModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires authentication for feature flag overrides endpoint', function (): void {
    $this->getJson('/api/v1/platform/feature-flag-overrides')
        ->assertUnauthorized();
});

it('requires authentication for effective feature flags endpoint', function (): void {
    $this->getJson('/api/v1/platform/feature-flags/effective')
        ->assertUnauthorized();
});

it('requires authentication for single effective feature flag endpoint', function (): void {
    $this->getJson('/api/v1/platform/feature-flags/platform.multi_facility_scoping/effective')
        ->assertUnauthorized();
});

it('lists persisted feature flag overrides with filters', function (): void {
    $user = User::factory()->create();

    seedFeatureFlagOverride('laboratory.loinc_required', 'country', 'TZ', true, 'TZ rollout');
    seedFeatureFlagOverride('platform.multi_facility_scoping', 'tenant', 'tenant-1', false, 'Tenant pilot disabled');
    seedFeatureFlagOverride('billing.multi_currency', 'country', 'KE', true, 'KE finance pilot');

    $this->actingAs($user)
        ->getJson('/api/v1/platform/feature-flag-overrides?scopeType=country&scopeKey=tz')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.filters.scopeType', 'country')
        ->assertJsonPath('meta.filters.scopeKey', 'TZ')
        ->assertJsonPath('data.0.flagName', 'laboratory.loinc_required')
        ->assertJsonPath('data.0.scopeType', 'country')
        ->assertJsonPath('data.0.scopeKey', 'TZ')
        ->assertJsonPath('data.0.enabled', true);
});

it('forbids creating feature flag override without manage permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/platform/feature-flag-overrides', [
            'flagName' => 'platform.multi_facility_scoping',
            'scopeType' => 'country',
            'scopeKey' => 'TZ',
            'enabled' => true,
        ])
        ->assertForbidden();
});

it('creates feature flag override when authorized', function (): void {
    $user = User::factory()->create();
    grantFeatureFlagOverrideManagePermission($user);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/feature-flag-overrides', [
            'flagName' => 'platform.multi_facility_scoping',
            'scopeType' => 'country',
            'scopeKey' => 'tz',
            'enabled' => true,
            'reason' => 'TZ pilot enable',
            'metadata' => ['ticket' => 'PLAT-101'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.flagName', 'platform.multi_facility_scoping')
        ->assertJsonPath('data.scopeType', 'country')
        ->assertJsonPath('data.scopeKey', 'TZ')
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.reason', 'TZ pilot enable')
        ->assertJsonPath('data.metadata.ticket', 'PLAT-101');

    expect(DB::table('feature_flag_overrides')->count())->toBe(1);
});

it('rejects creating override for unknown feature flag', function (): void {
    $user = User::factory()->create();
    grantFeatureFlagOverrideManagePermission($user);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/feature-flag-overrides', [
            'flagName' => 'unknown.flag',
            'scopeType' => 'country',
            'scopeKey' => 'TZ',
            'enabled' => true,
        ])
        ->assertStatus(422);
});

it('resolves effective feature flags using precedence global country tenant facility', function (): void {
    $user = User::factory()->create();

    [$tenantId, $facilityId] = seedPlatformScopeForFeatureFlags(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-01',
        facilityName: 'Nairobi General',
    );

    seedFeatureFlagOverride('platform.multi_facility_scoping', 'country', 'KE', true, 'Country pilot on');
    seedFeatureFlagOverride('platform.multi_facility_scoping', 'tenant', $tenantId, false, 'Tenant pilot paused');
    seedFeatureFlagOverride('platform.multi_facility_scoping', 'facility', $facilityId, true, 'Facility re-enabled for testing');
    seedFeatureFlagOverride('platform.multi_tenant_isolation', 'tenant', $tenantId, true, 'Tenant isolation pilot');

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-01',
        ])
        ->getJson('/api/v1/platform/feature-flags/effective')
        ->assertOk()
        ->assertJsonPath('meta.scope.countryCode', 'KE')
        ->assertJsonPath('meta.scope.tenantId', $tenantId)
        ->assertJsonPath('meta.scope.facilityId', $facilityId)
        ->assertJsonPath('meta.precedence.0', 'global')
        ->assertJsonPath('meta.precedence.3', 'facility');

    $flagsByName = collect($response->json('data'))
        ->keyBy(static fn (array $item): string => (string) $item['name']);

    expect($flagsByName['platform.multi_facility_scoping']['baseEnabled'])->toBeFalse();
    expect($flagsByName['platform.multi_facility_scoping']['enabled'])->toBeTrue();
    expect($flagsByName['platform.multi_facility_scoping']['resolution']['source'])->toBe('facility');
    expect($flagsByName['platform.multi_facility_scoping']['appliedOverride']['scopeType'])->toBe('facility');

    expect($flagsByName['platform.multi_tenant_isolation']['baseEnabled'])->toBeFalse();
    expect($flagsByName['platform.multi_tenant_isolation']['enabled'])->toBeTrue();
    expect($flagsByName['platform.multi_tenant_isolation']['resolution']['source'])->toBe('tenant');
});

it('filters effective feature flags by prefix and enabled status after applying overrides', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();

    seedFeatureFlagOverride('laboratory.loinc_required', 'country', 'TZ', true, 'TZ requires LOINC');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/platform/feature-flags/effective?prefix=laboratory.&enabledOnly=true')
        ->assertOk()
        ->assertJsonPath('meta.prefix', 'laboratory.')
        ->assertJsonPath('meta.enabledOnly', true)
        ->assertJsonPath('meta.scope.countryCode', 'TZ')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'laboratory.loinc_required')
        ->assertJsonPath('data.0.enabled', true)
        ->assertJsonPath('data.0.resolution.source', 'country');

    expect(collect($response->json('data'))->pluck('name')->all())
        ->toBe(['laboratory.loinc_required']);
});

it('returns a single effective feature flag with applied override details', function (): void {
    $user = User::factory()->create();

    [$tenantId, $facilityId] = seedPlatformScopeForFeatureFlags(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-01',
        facilityName: 'Dar Main Hospital',
    );

    seedFeatureFlagOverride('platform.multi_facility_scoping', 'tenant', $tenantId, true, 'Tenant enabled');
    seedFeatureFlagOverride('platform.multi_facility_scoping', 'facility', $facilityId, false, 'Facility disabled temporarily');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZH',
            'X-Facility-Code' => 'DAR-01',
        ])
        ->getJson('/api/v1/platform/feature-flags/platform.multi_facility_scoping/effective')
        ->assertOk()
        ->assertJsonPath('data.name', 'platform.multi_facility_scoping')
        ->assertJsonPath('data.baseEnabled', false)
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.resolution.source', 'facility')
        ->assertJsonPath('data.appliedOverride.scopeType', 'facility')
        ->assertJsonPath('meta.scope.tenantId', $tenantId)
        ->assertJsonPath('meta.scope.facilityId', $facilityId);
});

it('returns 404 for unknown single effective feature flag endpoint', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/feature-flags/not.a.real.flag/effective')
        ->assertNotFound();
});

it('updates feature flag override when authorized', function (): void {
    $user = User::factory()->create();
    grantFeatureFlagOverrideManagePermission($user);

    $overrideId = seedFeatureFlagOverride('laboratory.loinc_required', 'country', 'TZ', false, 'initial');

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/feature-flag-overrides/'.$overrideId, [
            'enabled' => true,
            'reason' => 'Mandated in TZ',
            'metadata' => ['source' => 'policy'],
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $overrideId)
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.reason', 'Mandated in TZ')
        ->assertJsonPath('data.metadata.source', 'policy');

    expect(DB::table('feature_flag_overrides')->where('id', $overrideId)->value('enabled'))->toBe(1);

    $auditLog = FeatureFlagOverrideAuditLogModel::query()
        ->where('feature_flag_override_id', $overrideId)
        ->where('action', 'updated')
        ->latest('created_at')
        ->first();

    expect($auditLog)->not->toBeNull();
    expect($auditLog?->metadata['transition']['from'] ?? null)->toBeFalse();
    expect($auditLog?->metadata['transition']['to'] ?? null)->toBeTrue();
    expect($auditLog?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('rejects empty patch payload for feature flag override update', function (): void {
    $user = User::factory()->create();
    grantFeatureFlagOverrideManagePermission($user);

    $overrideId = seedFeatureFlagOverride('laboratory.loinc_required', 'country', 'TZ', false);

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/feature-flag-overrides/'.$overrideId, [])
        ->assertStatus(422);
});

it('rejects immutable identity fields on feature flag override update', function (): void {
    $user = User::factory()->create();
    grantFeatureFlagOverrideManagePermission($user);

    $overrideId = seedFeatureFlagOverride('laboratory.loinc_required', 'country', 'TZ', false, 'initial');

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/feature-flag-overrides/'.$overrideId, [
            'flagName' => 'platform.multi_tenant_isolation',
            'scopeType' => 'tenant',
            'scopeKey' => 'tenant-override',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['flagName', 'scopeType', 'scopeKey']);
});

it('deletes feature flag override when authorized', function (): void {
    $user = User::factory()->create();
    grantFeatureFlagOverrideManagePermission($user);

    $overrideId = seedFeatureFlagOverride('platform.multi_tenant_isolation', 'country', 'KE', true);

    $this->actingAs($user)
        ->deleteJson('/api/v1/platform/feature-flag-overrides/'.$overrideId)
        ->assertNoContent();

    expect(DB::table('feature_flag_overrides')->where('id', $overrideId)->exists())->toBeFalse();
});

it('forbids feature flag override audit log listing without permission', function (): void {
    $user = User::factory()->create();
    $overrideId = seedFeatureFlagOverride('billing.multi_currency', 'country', 'KE', true);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/feature-flag-overrides/'.$overrideId.'/audit-logs')
        ->assertForbidden();
});

it('lists feature flag override audit logs when authorized', function (): void {
    $user = User::factory()->create();
    grantFeatureFlagOverrideManagePermission($user);
    grantFeatureFlagOverrideAuditPermission($user);

    $overrideId = seedFeatureFlagOverride('laboratory.loinc_required', 'country', 'TZ', false, 'initial');

    $this->actingAs($user)->patchJson('/api/v1/platform/feature-flag-overrides/'.$overrideId, [
        'enabled' => true,
        'reason' => 'TZ enforced',
    ])->assertOk();

    $this->actingAs($user)->deleteJson('/api/v1/platform/feature-flag-overrides/'.$overrideId)
        ->assertNoContent();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/platform/feature-flag-overrides/'.$overrideId.'/audit-logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $actions = collect($response->json('data'))->pluck('action')->all();
    expect($actions)->toContain('updated');
    expect($actions)->toContain('deleted');
});

function seedFeatureFlagOverride(
    string $flagName,
    string $scopeType,
    string $scopeKey,
    bool $enabled,
    ?string $reason = null,
): string {
    $id = (string) Str::uuid();

    DB::table('feature_flag_overrides')->insert([
        'id' => $id,
        'flag_name' => $flagName,
        'scope_type' => strtolower($scopeType),
        'scope_key' => $scopeType === 'country' ? strtoupper($scopeKey) : $scopeKey,
        'enabled' => $enabled,
        'reason' => $reason,
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function grantFeatureFlagOverrideManagePermission(User $user): void
{
    $user->givePermissionTo('platform.feature-flag-overrides.manage');
}

function grantFeatureFlagOverrideAuditPermission(User $user): void
{
    $user->givePermissionTo('platform.feature-flag-overrides.view-audit-logs');
}

/**
 * @return array{0:string,1:string}
 */
function seedPlatformScopeForFeatureFlags(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedTenantAndFacilityForFeatureFlags(
        tenantCode: $tenantCode,
        tenantName: $tenantName,
        countryCode: $countryCode,
        facilityCode: $facilityCode,
        facilityName: $facilityName,
    );

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'administrator',
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
function seedTenantAndFacilityForFeatureFlags(
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => $tenantCode,
        'name' => $tenantName,
        'country_code' => $countryCode,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => $facilityCode,
        'name' => $facilityName,
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Nairobi',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}
