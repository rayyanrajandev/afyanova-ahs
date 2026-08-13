<?php

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires authentication for platform access scope endpoint', function (): void {
    $this->getJson('/api/v1/platform/access-scope')
        ->assertUnauthorized();
});

it('returns unresolved scope when user has no facility assignments and no headers', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/access-scope')
        ->assertOk()
        ->assertJsonPath('data.resolvedFrom', 'none')
        ->assertJsonPath('data.tenant', null)
        ->assertJsonPath('data.facility', null)
        ->assertJsonPath('data.userAccess.accessibleFacilityCount', 0);
});

it('auto selects scope when user has a single active facility assignment', function (): void {
    $user = User::factory()->create();

    [$tenantId, $facilityId] = seedPlatformScope(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-MAIN',
        facilityName: 'Dar Main Hospital',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/access-scope')
        ->assertOk()
        ->assertJsonPath('data.resolvedFrom', 'single_assignment')
        ->assertJsonPath('data.tenant.id', $tenantId)
        ->assertJsonPath('data.tenant.code', 'TZH')
        ->assertJsonPath('data.facility.id', $facilityId)
        ->assertJsonPath('data.facility.code', 'DAR-MAIN')
        ->assertJsonPath('data.userAccess.accessibleFacilityCount', 1);
});

it('resolves explicit tenant and facility headers when user has access', function (): void {
    $user = User::factory()->create();

    seedPlatformScope(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-01',
        facilityName: 'Nairobi General',
        isPrimary: false,
    );

    seedPlatformScope(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-02',
        facilityName: 'Mombasa Regional',
        isPrimary: true,
    );

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'eah',
            'X-Facility-Code' => 'msa-02',
        ])
        ->getJson('/api/v1/platform/access-scope')
        ->assertOk()
        ->assertJsonPath('data.resolvedFrom', 'headers')
        ->assertJsonPath('data.tenant.code', 'EAH')
        ->assertJsonPath('data.facility.code', 'MSA-02');
});

it('lets facility super admin switch across all active facilities', function (): void {
    $user = User::factory()->create();

    seedPlatformScope(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-MAIN',
        facilityName: 'Dar Main Hospital',
    );

    $secondFacility = seedTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DSK-DISP',
        facilityName: 'DSK Dispensary',
    );

    DB::table('facility_user')
        ->where('user_id', $user->id)
        ->update(['role' => 'super_admin']);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/access-scope')
        ->assertOk()
        ->assertJsonPath('data.userAccess.accessibleFacilityCount', 2)
        ->assertJsonPath('data.userAccess.facilities.0.code', 'DAR-MAIN')
        ->assertJsonPath('data.userAccess.facilities.1.code', 'DSK-DISP');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZH',
            'X-Facility-Code' => 'DSK-DISP',
        ])
        ->getJson('/api/v1/platform/access-scope')
        ->assertOk()
        ->assertJsonPath('data.resolvedFrom', 'headers')
        ->assertJsonPath('data.facility.id', $secondFacility[1])
        ->assertJsonPath('data.facility.code', 'DSK-DISP');
});

it('lets system super admin switch across all active facilities without facility assignment', function (): void {
    $user = User::factory()->create();

    $role = RoleModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'code' => 'PLATFORM.SUPER.ADMIN',
        'name' => 'System Super Admin',
        'status' => 'active',
        'is_system' => true,
    ]);

    $user->roles()->syncWithoutDetaching([$role->id]);

    seedTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-MAIN',
        facilityName: 'Dar Main Hospital',
    );

    $secondFacility = seedTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DSK-DISP',
        facilityName: 'DSK Dispensary',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/access-scope')
        ->assertOk()
        ->assertJsonPath('data.userAccess.accessibleFacilityCount', 2)
        ->assertJsonPath('data.userAccess.facilities.0.code', 'DAR-MAIN')
        ->assertJsonPath('data.userAccess.facilities.1.code', 'DSK-DISP');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZH',
            'X-Facility-Code' => 'DSK-DISP',
        ])
        ->getJson('/api/v1/platform/access-scope')
        ->assertOk()
        ->assertJsonPath('data.resolvedFrom', 'headers')
        ->assertJsonPath('data.facility.id', $secondFacility[1])
        ->assertJsonPath('data.facility.code', 'DSK-DISP');
});

it('lets a platform admin (is_platform_admin only, no legacy role assignment) switch across all active facilities', function (): void {
    $user = User::factory()->create(['is_platform_admin' => true]);

    seedTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-MAIN',
        facilityName: 'Dar Main Hospital',
    );

    $secondFacility = seedTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DSK-DISP',
        facilityName: 'DSK Dispensary',
    );

    // No facility_user row and no role_user row at all — is_platform_admin
    // alone must be sufficient, matching User::isPlatformSuperAdmin()'s logic.
    expect(DB::table('facility_user')->where('user_id', $user->id)->exists())->toBeFalse();
    expect(DB::table('role_user')->where('user_id', $user->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/access-scope')
        ->assertOk()
        ->assertJsonPath('data.userAccess.accessibleFacilityCount', 2)
        ->assertJsonPath('data.userAccess.facilities.0.code', 'DAR-MAIN')
        ->assertJsonPath('data.userAccess.facilities.1.code', 'DSK-DISP');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZH',
            'X-Facility-Code' => 'DSK-DISP',
        ])
        ->getJson('/api/v1/platform/access-scope')
        ->assertOk()
        ->assertJsonPath('data.resolvedFrom', 'headers')
        ->assertJsonPath('data.facility.id', $secondFacility[1])
        ->assertJsonPath('data.facility.code', 'DSK-DISP');
});

it('treats blank scope headers as absent and falls back to single-assignment resolution', function (): void {
    $user = User::factory()->create();

    [$tenantId, $facilityId] = seedPlatformScope(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-MAIN',
        facilityName: 'Dar Main Hospital',
    );

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => '   ',
            'X-Facility-Code' => '',
        ])
        ->getJson('/api/v1/platform/access-scope')
        ->assertOk()
        ->assertJsonPath('data.resolvedFrom', 'single_assignment')
        ->assertJsonPath('data.tenant.id', $tenantId)
        ->assertJsonPath('data.facility.id', $facilityId)
        ->assertJsonPath('data.headers.tenantCode', null)
        ->assertJsonPath('data.headers.facilityCode', null);
});

it('auto selects the primary facility when no scope is provided and multiple facilities are accessible', function (): void {
    $user = User::factory()->create();

    [$tenantId, $primaryFacilityId] = seedPlatformScope(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-MAIN',
        facilityName: 'Dar Main Hospital',
        isPrimary: true,
    );

    seedPlatformScope(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DSK-DISP',
        facilityName: 'DSK Dispensary',
        isPrimary: false,
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/access-scope')
        ->assertOk()
        ->assertJsonPath('data.resolvedFrom', 'primary_assignment')
        ->assertJsonPath('data.tenant.id', $tenantId)
        ->assertJsonPath('data.facility.id', $primaryFacilityId)
        ->assertJsonPath('data.facility.code', 'DAR-MAIN');
});

it('resolves scope from cookies when headers are absent', function (): void {
    $user = User::factory()->create();

    seedPlatformScope(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-01',
        facilityName: 'Nairobi General',
        isPrimary: false,
    );

    seedPlatformScope(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-02',
        facilityName: 'Mombasa Regional',
        isPrimary: true,
    );

    $this->actingAs($user)
        ->withUnencryptedCookie('platform_tenant_code', 'eah')
        ->withUnencryptedCookie('platform_facility_code', 'msa-02')
        ->get('/api/v1/platform/access-scope', ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.resolvedFrom', 'headers')
        ->assertJsonPath('data.tenant.code', 'EAH')
        ->assertJsonPath('data.facility.code', 'MSA-02');
});

it('ignores invalid scope cookies and falls back to unresolved scope', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withUnencryptedCookie('platform_tenant_code', 'UNKNOWN')
        ->withUnencryptedCookie('platform_facility_code', 'NOPE')
        ->get('/api/v1/platform/access-scope', ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.resolvedFrom', 'none')
        ->assertJsonPath('data.tenant', null)
        ->assertJsonPath('data.facility', null);
});

it('returns 404 when tenant header is unknown', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'UNKNOWN',
        ])
        ->getJson('/api/v1/platform/access-scope')
        ->assertNotFound();
});

it('returns 404 when facility header is unknown within a valid tenant', function (): void {
    $user = User::factory()->create();

    seedPlatformScope(
        userId: $user->id,
        tenantCode: 'UGH',
        tenantName: 'Uganda Health',
        countryCode: 'UG',
        facilityCode: 'KLA-01',
        facilityName: 'Kampala Central',
    );

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'UGH',
            'X-Facility-Code' => 'NOPE',
        ])
        ->getJson('/api/v1/platform/access-scope')
        ->assertNotFound();
});

it('returns 403 when user requests facility they are not assigned to', function (): void {
    $user = User::factory()->create();

    seedPlatformScope(
        userId: $user->id,
        tenantCode: 'RWH',
        tenantName: 'Rwanda Health',
        countryCode: 'RW',
        facilityCode: 'KGL-01',
        facilityName: 'Kigali Central',
    );

    seedTenantAndFacility(
        tenantCode: 'RWH',
        tenantName: 'Rwanda Health',
        countryCode: 'RW',
        facilityCode: 'KGL-02',
        facilityName: 'Kigali West',
    );

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'RWH',
            'X-Facility-Code' => 'KGL-02',
        ])
        ->getJson('/api/v1/platform/access-scope')
        ->assertForbidden();
});

/**
 * @return array{0:string,1:string}
 */
function seedPlatformScope(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
    bool $isPrimary = true,
): array {
    [$tenantId, $facilityId] = seedTenantAndFacility(
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
        'is_primary' => $isPrimary,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}

/**
 * @return array{0:string,1:string}
 */
function seedTenantAndFacility(
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    $tenant = DB::table('tenants')
        ->where('code', $tenantCode)
        ->first();

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
