<?php

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanModel;
use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanAuditLogModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $plan = PlatformSubscriptionPlanModel::query()->where('code', 'patient_registration')->firstOrFail();
    $this->plan = $plan;
});

it('creates a new entitlement on a plan', function (): void {
    $user = makeUserWithRole(['platform.subscription-plans.manage']);

    $this->actingAs($user)->postJson(
        '/api/v1/platform/admin/service-plans/'.$this->plan->id.'/entitlements',
        [
            'entitlementKey' => 'billing.custom_module',
            'entitlementLabel' => 'Custom billing module',
            'entitlementGroup' => 'Revenue Cycle',
            'entitlementType' => 'feature',
            'limitValue' => null,
            'enabled' => true,
        ],
    )
        ->assertCreated()
        ->assertJsonPath('data.entitlements', function (array $entitlements): bool {
            return collect($entitlements)->contains(
                fn (array $e): bool => $e['key'] === 'billing.custom_module'
                    && $e['label'] === 'Custom billing module'
                    && $e['enabled'] === true,
            );
        });

    $this->assertDatabaseHas('platform_subscription_plan_entitlements', [
        'plan_id' => $this->plan->id,
        'entitlement_key' => 'billing.custom_module',
        'entitlement_label' => 'Custom billing module',
        'entitlement_group' => 'Revenue Cycle',
        'enabled' => 1,
    ]);

    $this->assertDatabaseHas('platform_subscription_plan_audit_logs', [
        'plan_id' => $this->plan->id,
        'action' => 'platform.subscription-plans.updated',
    ]);
});

it('rejects duplicate entitlement key on the same plan', function (): void {
    $user = makeUserWithRole(['platform.subscription-plans.manage']);

    $this->actingAs($user)->postJson(
        '/api/v1/platform/admin/service-plans/'.$this->plan->id.'/entitlements',
        [
            'entitlementKey' => 'patients.registration',
            'entitlementLabel' => 'Duplicate',
            'entitlementGroup' => 'Patient Access',
            'enabled' => true,
        ],
    )
        ->assertStatus(422)
        ->assertJsonPath('message', 'This plan already includes the entitlement patients.registration.');
});

it('rejects invalid entitlement key format', function (): void {
    $user = makeUserWithRole(['platform.subscription-plans.manage']);

    $this->actingAs($user)->postJson(
        '/api/v1/platform/admin/service-plans/'.$this->plan->id.'/entitlements',
        [
            'entitlementKey' => 'INVALID KEY with spaces',
            'entitlementLabel' => 'Invalid',
            'enabled' => true,
        ],
    )
        ->assertStatus(422);
});

it('requires manage permission', function (): void {
    $user = makeUserWithRole([]);

    $this->actingAs($user)->postJson(
        '/api/v1/platform/admin/service-plans/'.$this->plan->id.'/entitlements',
        [
            'entitlementKey' => 'billing.no_permission',
            'entitlementLabel' => 'No permission',
            'enabled' => true,
        ],
    )
        ->assertForbidden();
});