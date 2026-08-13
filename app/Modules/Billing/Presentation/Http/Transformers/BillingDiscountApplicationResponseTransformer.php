<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingDiscountApplicationResponseTransformer
{
    public static function transform(array $discount): array
    {
        return [
            'id' => $discount['id'] ?? null,
            'billing_invoice_id' => $discount['billing_invoice_id'] ?? null,
            'billing_discount_policy_id' => $discount['billing_discount_policy_id'] ?? null,
            'original_amount' => isset($discount['original_amount']) ? (float) $discount['original_amount'] : null,
            'discount_amount' => isset($discount['discount_amount']) ? (float) $discount['discount_amount'] : null,
            'final_amount' => isset($discount['final_amount']) ? (float) $discount['final_amount'] : null,
            'applied_by_user_id' => $discount['applied_by_user_id'] ?? null,
            'applied_at' => $discount['applied_at'] ?? null,
            'reason' => $discount['reason'] ?? null,
            'original_total' => isset($discount['original_total']) ? (float) $discount['original_total'] : null,
            'discount_applied' => isset($discount['discount_applied']) ? (float) $discount['discount_applied'] : null,
            'new_total' => isset($discount['new_total']) ? (float) $discount['new_total'] : null,
            'created_at' => $discount['created_at'] ?? null,
            'updated_at' => $discount['updated_at'] ?? null,
        ];
    }
}
