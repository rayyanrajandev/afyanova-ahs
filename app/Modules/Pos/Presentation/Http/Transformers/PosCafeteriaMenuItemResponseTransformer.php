<?php

namespace App\Modules\Pos\Presentation\Http\Transformers;

class PosCafeteriaMenuItemResponseTransformer
{
    public static function transform(array $item): array
    {
        return [
            'id' => $item['id'] ?? null,
            'tenantId' => $item['tenant_id'] ?? null,
            'facilityId' => $item['facility_id'] ?? null,
            'itemCode' => $item['item_code'] ?? null,
            'itemName' => $item['item_name'] ?? null,
            'category' => $item['category'] ?? null,
            'unitLabel' => $item['unit_label'] ?? null,
            'unitPrice' => $item['unit_price'] ?? null,
            'taxRatePercent' => $item['tax_rate_percent'] ?? null,
            'status' => $item['status'] ?? null,
            'statusReason' => $item['status_reason'] ?? null,
            'sortOrder' => $item['sort_order'] ?? null,
            'description' => $item['description'] ?? null,
            'metadata' => is_array($item['metadata'] ?? null) ? $item['metadata'] : null,
            'createdByUserId' => $item['created_by_user_id'] ?? null,
            'updatedByUserId' => $item['updated_by_user_id'] ?? null,
            'createdAt' => $item['created_at'] ?? null,
            'updatedAt' => $item['updated_at'] ?? null,
        ];
    }
}
