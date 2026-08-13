<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanEntitlementModel;
use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanModel;

class PlatformSubscriptionPlanResponseTransformer
{
    /**
     * @return array<string, mixed>
     */
    public static function transform(PlatformSubscriptionPlanModel $plan): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'billingCycle' => $plan->billing_cycle,
            'priceAmount' => $plan->price_amount,
            'currencyCode' => $plan->currency_code,
            'status' => $plan->status,
            'sortOrder' => $plan->sort_order,
            'metadata' => $plan->metadata ?? [],
            'entitlements' => $plan->entitlements
                ->map(static fn (PlatformSubscriptionPlanEntitlementModel $entitlement): array => [
                    'id' => $entitlement->id,
                    'key' => $entitlement->entitlement_key,
                    'label' => $entitlement->entitlement_label,
                    'group' => $entitlement->entitlement_group,
                    'type' => $entitlement->entitlement_type,
                    'limitValue' => $entitlement->limit_value,
                    'enabled' => $entitlement->enabled,
                    'metadata' => $entitlement->metadata ?? [],
                ])
                ->values()
                ->all(),
        ];
    }
}
