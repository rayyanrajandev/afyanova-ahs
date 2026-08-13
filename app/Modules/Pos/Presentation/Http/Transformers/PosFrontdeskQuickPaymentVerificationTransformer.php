<?php

namespace App\Modules\Pos\Presentation\Http\Transformers;

class PosFrontdeskQuickPaymentVerificationTransformer
{
    public static function transform(array $result): array
    {
        return [
            'paid' => (bool) ($result['paid'] ?? false),
            'message' => $result['message'] ?? null,
            'sourceKind' => $result['source_kind'] ?? null,
            'orderId' => $result['order_id'] ?? null,
            'saleId' => $result['sale_id'] ?? null,
            'saleNumber' => $result['sale_number'] ?? null,
            'receiptNumber' => $result['receipt_number'] ?? null,
            'soldAt' => $result['sold_at'] ?? null,
            'payments' => $result['payments'] ?? [],
        ];
    }
}
