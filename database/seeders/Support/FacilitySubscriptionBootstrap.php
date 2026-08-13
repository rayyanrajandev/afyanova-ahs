<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Modules\Platform\Infrastructure\Models\FacilitySubscriptionModel;
use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanModel;
use Illuminate\Support\Facades\Schema;

/**
 * Ensures a facility has an active plan subscription so subscription-mapped API routes
 * (e.g. service requests, medical records) work in local/staging after tenant bootstrap.
 */
final class FacilitySubscriptionBootstrap
{
    public static function ensureActiveSubscription(string $tenantId, string $facilityId): bool
    {
        if (! Schema::hasTable('facility_subscriptions') || ! Schema::hasTable('platform_subscription_plans')) {
            return false;
        }

        $plan = PlatformSubscriptionPlanModel::query()
            ->where('code', 'clinical_operations')
            ->first()
            ?? PlatformSubscriptionPlanModel::query()
                ->where('code', 'patient_registration')
                ->first();

        if ($plan === null) {
            return false;
        }

        FacilitySubscriptionModel::query()->updateOrCreate(
            ['facility_id' => $facilityId],
            [
                'tenant_id' => $tenantId,
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'price_amount' => $plan->price_amount,
                'currency_code' => $plan->currency_code,
                'current_period_starts_at' => now()->startOfDay(),
                'current_period_ends_at' => now()->addMonth(),
                'metadata' => ['seeded_by' => 'facility_subscription_bootstrap'],
            ],
        );

        return true;
    }
}
