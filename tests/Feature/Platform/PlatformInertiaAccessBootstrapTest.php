<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\FacilitySubscriptionModel;
use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares auth permissions and platform bootstrap scope in inertia payload', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $user->givePermissionTo('patients.read');

    $role = RoleModel::query()->create([
        'code' => 'ADMIN.REGISTRATION',
        'name' => 'Registration Admin',
        'status' => 'active',
        'is_system' => false,
    ]);
    $user->roles()->syncWithoutDetaching([$role->id]);

    seedInertiaScopeAssignment(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-MAIN',
        facilityName: 'Dar Main Hospital',
    );
    seedInertiaActivePatientRegistrationSubscription(
        tenantCode: 'TZH',
        facilityCode: 'DAR-MAIN',
    );

    $this->actingAs($user)
        ->get('/patients')
        ->assertInertia(fn (Assert $page) => $page
            ->component('patients/IndexV2')
            ->has('auth.permissions')
            ->where('auth.permissions.0', 'patients.read')
            ->where('platform.scope.resolvedFrom', 'single_assignment')
            ->where('platform.scope.tenant.code', 'TZH')
            ->where('platform.scope.facility.code', 'DAR-MAIN')
            ->where('platform.featureFlags.multiTenantIsolation', false)
            ->where('platform.featureFlags.multiFacilityScoping', false));
});

it('shares universal platform access for system super admin without facility assignment', function (): void {
    Permission::query()->firstOrCreate(['name' => 'platform.facilities.create']);
    Permission::query()->firstOrCreate(['name' => 'platform.facilities.read']);

    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $role = RoleModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'code' => 'PLATFORM.SUPER.ADMIN',
        'name' => 'System Super Admin',
        'status' => 'active',
        'is_system' => true,
    ]);

    $user->roles()->syncWithoutDetaching([$role->id]);

    expect($user->isPlatformSuperAdminAccess())->toBeTrue()
        ->and($user->hasPermissionTo('platform.facilities.create'))->toBeTrue();

    $this->actingAs($user)
        ->get('/platform/admin/facility-config')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/admin/facility-config/Index')
            ->where('auth.isPlatformSuperAdmin', true)
            ->where('auth.isFacilitySuperAdmin', true)
            ->where('auth.permissions', function (mixed $permissions): bool {
                $permissionNames = $permissions instanceof \Illuminate\Support\Collection
                    ? $permissions->all()
                    : (array) $permissions;

                return in_array('platform.facilities.create', $permissionNames, true)
                    && in_array('platform.facilities.read', $permissionNames, true);
            }));
});

/**
 * @return array{0:string,1:string}
 */
function seedInertiaScopeAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedInertiaTenantAndFacility(
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

function seedInertiaActivePatientRegistrationSubscription(string $tenantCode, string $facilityCode): void
{
    $tenant = DB::table('tenants')
        ->where('code', $tenantCode)
        ->first();
    $facility = DB::table('facilities')
        ->where('code', $facilityCode)
        ->first();

    if ($tenant === null || $facility === null) {
        return;
    }

    $plan = PlatformSubscriptionPlanModel::query()
        ->where('code', 'patient_registration')
        ->firstOrFail();

    FacilitySubscriptionModel::query()->create([
        'tenant_id' => (string) $tenant->id,
        'facility_id' => (string) $facility->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'billing_cycle' => 'monthly',
        'price_amount' => $plan->price_amount,
        'currency_code' => $plan->currency_code,
        'current_period_starts_at' => now()->startOfDay(),
        'current_period_ends_at' => now()->addMonth(),
        'metadata' => [],
    ]);
}

/**
 * @return array{0:string,1:string}
 */
function seedInertiaTenantAndFacility(
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
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
