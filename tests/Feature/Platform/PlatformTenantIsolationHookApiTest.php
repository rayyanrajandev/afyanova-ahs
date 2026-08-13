<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('does not block platform access scope endpoint when multi tenant isolation is enabled', function (): void {
    $user = User::factory()->create();

    seedTenantIsolationCountryOverride('TZ');

    $this->actingAs($user)
        ->getJson('/api/v1/platform/access-scope')
        ->assertOk()
        ->assertJsonPath('data.resolvedFrom', 'none');
});

it('blocks operational routes when multi tenant isolation is enabled and tenant scope is unresolved', function (): void {
    $user = User::factory()->create();

    seedTenantIsolationCountryOverride('TZ');

    $this->actingAs($user)
        ->getJson('/api/v1/appointments')
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED')
        ->assertJsonPath('meta.flagName', 'platform.multi_tenant_isolation')
        ->assertJsonPath('meta.resolvedFrom', 'none')
        ->assertJsonPath('meta.routeName', 'appointments.index');
});

it('allows operational routes when multi tenant isolation is enabled and tenant scope resolves', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('appointments.read');

    seedTenantIsolationCountryOverride('TZ');
    seedTenantIsolationAssignment(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-01',
        facilityName: 'Dar Main Hospital',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

function seedTenantIsolationCountryOverride(string $countryCode): void
{
    DB::table('feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_name' => 'platform.multi_tenant_isolation',
        'scope_type' => 'country',
        'scope_key' => strtoupper($countryCode),
        'enabled' => true,
        'reason' => 'tenant isolation rollout test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array{0:string,1:string}
 */
function seedTenantIsolationAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedTenantIsolationFacility(
        tenantCode: $tenantCode,
        tenantName: $tenantName,
        countryCode: $countryCode,
        facilityCode: $facilityCode,
        facilityName: $facilityName,
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
function seedTenantIsolationFacility(
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    $tenant = DB::table('tenants')->where('code', $tenantCode)->first();

    if ($tenant === null) {
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'code' => $tenantCode,
            'name' => $tenantName,
            'country_code' => $countryCode,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        $tenantId = (string) $tenant->id;
    }

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
