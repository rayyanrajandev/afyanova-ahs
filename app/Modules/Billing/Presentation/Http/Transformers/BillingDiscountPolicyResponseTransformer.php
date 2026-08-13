<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingDiscountPolicyResponseTransformer
{
    public static function transform(array $policy): array
    {
        return [
            'id' => $policy['id'] ?? null,
            'tenant_id' => $policy['tenant_id'] ?? null,
            'facility_id' => $policy['facility_id'] ?? null,
            'code' => $policy['code'] ?? null,
            'name' => $policy['name'] ?? null,
            'description' => $policy['description'] ?? null,
            'discount_type' => $policy['discount_type'] ?? null,
            'discount_value' => $policy['discount_value'] ?? null,
            'discount_percentage' => $policy['discount_percentage'] ?? null,
            'applicable_services' => $policy['applicable_services'] ?? null,
            'auto_apply' => $policy['auto_apply'] ?? null,
            'requires_approval_above_amount' => $policy['requires_approval_above_amount'] ?? null,
            'active_from_date' => $policy['active_from_date'] ?? null,
            'active_to_date' => $policy['active_to_date'] ?? null,
            'status' => $policy['status'] ?? null,
            'created_by_user_id' => $policy['created_by_user_id'] ?? null,
            'created_at' => $policy['created_at'] ?? null,
            'updated_at' => $policy['updated_at'] ?? null,
        ];
    }
}
