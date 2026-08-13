<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class CashBillingChargeResponseTransformer
{
    public static function transform(array $charge): array
    {
        return [
            'id' => $charge['id'] ?? null,
            'cash_billing_account_id' => $charge['cash_billing_account_id'] ?? null,
            'service_id' => $charge['service_id'] ?? null,
            'service_name' => $charge['service_name'] ?? null,
            'quantity' => isset($charge['quantity']) ? (int) $charge['quantity'] : null,
            'unit_price' => $charge['unit_price'] ?? null,
            'charge_amount' => $charge['charge_amount'] ?? null,
            'recorded_by_user_id' => $charge['recorded_by_user_id'] ?? null,
            'charge_date' => $charge['charge_date'] ?? null,
            'reference_id' => $charge['reference_id'] ?? null,
            'reference_type' => $charge['reference_type'] ?? null,
            'description' => $charge['description'] ?? null,
            'created_at' => $charge['created_at'] ?? null,
            'updated_at' => $charge['updated_at'] ?? null,
        ];
    }
}
