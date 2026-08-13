<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\Permission;
use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\FacilitySubscriptionModel;
use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function facilityAdminSmokeContext(): array
{
    $tenant = TenantModel::query()->create([
        'code' => 'DSK',
        'name' => 'DSK Dispensary Group',
        'country_code' => 'TZ',
        'allowed_country_codes' => ['TZ'],
        'status' => 'active',
    ]);

    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => 'DSK-DISP',
        'name' => 'DSK Dispensary',
        'facility_type' => 'dispensary',
        'facility_tier' => 'primary_care',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    $otherFacility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => 'DSK-OTHER',
        'name' => 'DSK Other Branch',
        'facility_type' => 'dispensary',
        'facility_tier' => 'primary_care',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'DSK Facility Admin',
        'email' => 'dsk.facility.admin@example.test',
    ]);

    $role = RoleModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
            'code' => 'ADMIN.FACILITY',
        'name' => 'Facility Administrator',
        'status' => 'active',
        'is_system' => true,
    ]);

    $permissions = [
        'patients.read',
        'patients.create',
        'patients.update',
        'laboratory.orders.read',
        'inventory.procurement.read',
        'staff.clinical-directory.read',
    ];

    foreach ($permissions as $permissionName) {
        $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    $user->roles()->syncWithoutDetaching([$role->id]);

    DB::table('facility_user')->insert([
        'facility_id' => $facility->id,
        'user_id' => $user->id,
        'role' => 'facility_admin',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = PlatformSubscriptionPlanModel::query()
        ->where('code', 'patient_registration')
        ->firstOrFail();

    FacilitySubscriptionModel::query()->create([
        'tenant_id' => $tenant->id,
        'facility_id' => $facility->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'billing_cycle' => 'monthly',
        'price_amount' => $plan->price_amount,
        'currency_code' => $plan->currency_code,
        'current_period_starts_at' => now()->startOfDay(),
        'current_period_ends_at' => now()->addMonth(),
        'metadata' => [],
    ]);

    return [
        'tenant' => $tenant,
        'facility' => $facility,
        'otherFacility' => $otherFacility,
        'user' => $user,
    ];
}

function facilityAdminSmokePatientPayload(array $overrides = []): array
{
    return array_merge([
        'firstName' => 'Asha',
        'middleName' => null,
        'lastName' => 'Msuya',
        'gender' => 'female',
        'dateOfBirth' => '1998-06-14',
        'phone' => '+255711000111',
        'email' => 'asha.msuya@example.test',
        'nationalId' => 'TZ-ACCESS-001',
        'countryCode' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Ilala',
        'addressLine' => 'Kisutu',
        'nextOfKinName' => 'Neema Msuya',
        'nextOfKinPhone' => '+255711000112',
    ], $overrides);
}

it('keeps facility admins out of platform-wide facility and subscription administration', function (): void {
    $context = facilityAdminSmokeContext();

    $this->actingAs($context['user'])
        ->get('/platform/admin/facility-config')
        ->assertForbidden();

    $this->actingAs($context['user'])
        ->get('/platform/admin/service-plans')
        ->assertForbidden();
});


it('blocks unsubscribed operational workspaces even when the role has module permission', function (): void {
    $this->withMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $context = facilityAdminSmokeContext();

    $this->actingAs($context['user'])
        ->get('/laboratory-orders')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/FacilitySubscriptionRequired')
            ->where('access.code', 'FACILITY_ENTITLEMENT_REQUIRED')
            ->where('access.missingEntitlements.0', 'laboratory.orders'));

    $clinicalPlan = PlatformSubscriptionPlanModel::query()
        ->where('code', 'clinical_operations')
        ->firstOrFail();

    FacilitySubscriptionModel::query()
        ->where('facility_id', $context['facility']->id)
        ->update([
            'plan_id' => $clinicalPlan->id,
            'price_amount' => $clinicalPlan->price_amount,
            'currency_code' => $clinicalPlan->currency_code,
            'updated_at' => now(),
        ]);

    $this->actingAs($context['user'])
        ->get('/laboratory-orders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('laboratory-orders/Index'));
});

it('blocks unsubscribed operational apis even when the role has module permission', function (): void {
    $this->withMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);

    $context = facilityAdminSmokeContext();

    $this->actingAs($context['user'])
        ->getJson('/api/v1/laboratory-orders/status-counts')
        ->assertForbidden()
        ->assertJsonPath('code', 'FACILITY_ENTITLEMENT_REQUIRED')
        ->assertJsonPath('missingEntitlements.0', 'laboratory.orders');

    $clinicalPlan = PlatformSubscriptionPlanModel::query()
        ->where('code', 'clinical_operations')
        ->firstOrFail();

    FacilitySubscriptionModel::query()
        ->where('facility_id', $context['facility']->id)
        ->update([
            'plan_id' => $clinicalPlan->id,
            'price_amount' => $clinicalPlan->price_amount,
            'currency_code' => $clinicalPlan->currency_code,
            'updated_at' => now(),
        ]);

    $this->actingAs($context['user'])
        ->getJson('/api/v1/laboratory-orders/status-counts')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('allows assigned facility admins to register patients only inside their facility scope and active subscription', function (): void {
    $context = facilityAdminSmokeContext();

    $this->actingAs($context['user'])
        ->get('/patients')
        ->assertOk();

    $this->actingAs($context['user'])
        ->postJson('/api/v1/patients', facilityAdminSmokePatientPayload())
        ->assertCreated()
        ->assertJsonPath('data.firstName', 'Asha');

    $this->actingAs($context['user'])
        ->withHeader('X-Facility-Code', $context['otherFacility']->code)
        ->postJson('/api/v1/patients', facilityAdminSmokePatientPayload([
            'phone' => '+255711000113',
            'email' => 'blocked.scope@example.test',
            'nationalId' => 'TZ-ACCESS-002',
        ]))
        ->assertForbidden();
});
