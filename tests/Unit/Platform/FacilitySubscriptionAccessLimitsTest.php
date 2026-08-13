<?php

use App\Modules\Platform\Application\Services\FacilitySubscriptionAccessService;
use App\Modules\Platform\Application\Services\PlanCatalogService;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\FacilitySubscriptionModel;
use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);
uses(RefreshDatabase::class);

function makeLimitsTestPlan(string $code, string $name): PlatformSubscriptionPlanModel
{
    return PlatformSubscriptionPlanModel::query()->updateOrCreate(
        ['code' => $code],
        [
            'name' => $name,
            'description' => null,
            'billing_cycle' => 'monthly',
            'price_amount' => '0.00',
            'currency_code' => 'TZS',
            'status' => 'active',
            'sort_order' => 1,
            'metadata' => [],
        ],
    );
}

function makeLimitsTestSubscription(string $facilityId, string $tenantId, string $planId): FacilitySubscriptionModel
{
    return FacilitySubscriptionModel::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'plan_id' => $planId,
        'status' => 'active',
        'billing_cycle' => 'monthly',
        'price_amount' => '0.00',
        'currency_code' => 'TZS',
        'current_period_starts_at' => now()->startOfDay(),
        'current_period_ends_at' => now()->addMonth(),
        'metadata' => [],
    ]);
}

it('hydrates quota limits from the plan catalog for the facility subscription plan', function (): void {
    $tenant = TenantModel::query()->create([
        'code' => 'LIMITS-TENANT',
        'name' => 'Limits Tenant',
        'country_code' => 'TZ',
    ]);
    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => 'LIMITS-FACILITY',
        'name' => 'Limits Facility',
        'facility_type' => 'Hospital',
        'status' => 'active',
    ]);

    $plan = makeLimitsTestPlan('patient_registration', 'Clinic Starter');
    makeLimitsTestSubscription(
        facilityId: (string) $facility->id,
        tenantId: (string) $tenant->id,
        planId: (string) $plan->id,
    );

    $scope = $this->createMock(CurrentPlatformScopeContextInterface::class);
    $scope->method('facilityId')->willReturn((string) $facility->id);

    $service = new FacilitySubscriptionAccessService(
        $scope,
        app(PlanCatalogService::class),
    );

    $limits = $service->limitsForFacility();

    // patient_registration has explicit catalog overrides.
    expect($limits['patients.monthly'])->toBe(1000)
        ->and($limits['staff.seats'])->toBe(5)
        ->and($limits['inventory.items.max'])->toBe(100);
});

it('returns empty limits when no scope facility is resolved', function (): void {
    $scope = $this->createMock(CurrentPlatformScopeContextInterface::class);
    $scope->method('facilityId')->willReturn(null);

    $service = new FacilitySubscriptionAccessService(
        $scope,
        app(PlanCatalogService::class),
    );

    expect($service->limitsForFacility())->toBe([]);
});

it('returns empty limits when the facility has no subscription', function (): void {
    $scope = $this->createMock(CurrentPlatformScopeContextInterface::class);
    $scope->method('facilityId')->willReturn((string) Str::uuid());

    $service = new FacilitySubscriptionAccessService(
        $scope,
        app(PlanCatalogService::class),
    );

    expect($service->limitsForFacility())->toBe([]);
});