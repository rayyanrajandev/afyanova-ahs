<?php

namespace App\Modules\Pos\Presentation\Http\Transformers;

use App\Modules\Pharmacy\Presentation\Http\Transformers\PharmacyMedicationAvailabilityResponseTransformer;

class PosPharmacyOtcCatalogItemResponseTransformer
{
    public static function transform(array $item): array
    {
        return [
            'id' => $item['id'] ?? null,
            'code' => $item['code'] ?? null,
            'name' => $item['name'] ?? null,
            'category' => $item['category'] ?? null,
            'unit' => $item['unit'] ?? null,
            'description' => $item['description'] ?? null,
            'status' => $item['status'] ?? null,
            'strength' => $item['strength'] ?? null,
            'dosageForm' => $item['dosage_form'] ?? null,
            'reviewMode' => $item['review_mode'] ?? null,
            'otcEligible' => (bool) ($item['otc_eligible'] ?? false),
            'otcEligibilityReason' => $item['otc_eligibility_reason'] ?? null,
            'otcUnitPrice' => $item['otc_unit_price'] ?? null,
            'otcUnitPriceSource' => $item['otc_unit_price_source'] ?? null,
            'stockState' => $item['stock_state'] ?? null,
            'availableQuantity' => $item['available_quantity'] ?? null,
            'inventoryItem' => PharmacyMedicationAvailabilityResponseTransformer::transform(
                is_array($item['inventory_item'] ?? null) ? $item['inventory_item'] : null,
            ),
            'metadata' => is_array($item['metadata'] ?? null) ? $item['metadata'] : null,
        ];
    }
}
