<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingInvoiceAdjustmentResponseTransformer
{
    public static function transform(array $adjustment): array
    {
        return [
            'id' => $adjustment['id'] ?? null,
            'type' => $adjustment['type'] ?? null,
            'amount' => (float) ($adjustment['amount'] ?? 0),
            'reason' => $adjustment['reason'] ?? null,
            'createdByUserId' => $adjustment['created_by_user_id'] ?? null,
            'createdAt' => $adjustment['created_at'] ?? null,
        ];
    }
}
