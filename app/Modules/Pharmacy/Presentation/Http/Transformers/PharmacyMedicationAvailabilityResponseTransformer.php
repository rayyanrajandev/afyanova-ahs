<?php

namespace App\Modules\Pharmacy\Presentation\Http\Transformers;

class PharmacyMedicationAvailabilityResponseTransformer
{
    public static function transform(?array $item): ?array
    {
        if ($item === null) {
            return null;
        }

        $rawBatches = $item['available_batches'] ?? [];

        return [
            'id' => $item['id'] ?? null,
            'itemCode' => $item['item_code'] ?? null,
            'itemName' => $item['item_name'] ?? null,
            'unit' => $item['unit'] ?? null,
            'dispensingUnit' => $item['dispensing_unit'] ?? null,
            'conversionFactor' => $item['conversion_factor'] ?? null,
            'currentStock' => $item['available_stock'] ?? $item['current_stock'] ?? null,
            'onHandStock' => $item['current_stock'] ?? null,
            'reorderLevel' => $item['reorder_level'] ?? null,
            'maxStockLevel' => $item['max_stock_level'] ?? null,
            'status' => $item['status'] ?? null,
            'stockState' => $item['stock_state'] ?? self::stockState($item),
            'batchTrackingMode' => $item['batch_tracking_mode'] ?? 'untracked',
            'blockedBatchQuantity' => $item['blocked_batch_quantity'] ?? 0,
            // Lets the dispense tab tell "this item is not batched at all" from
            // "it is batched and nothing is dispensable" — two states it used to
            // report with the same reassuring sentence.
            'hasBatchRecords' => $item['has_batch_records'] ?? false,
            'validBatchCount' => $item['valid_batch_count'] ?? 0,
            'availableBatches' => array_map(static fn (array $b): array => [
                'id' => $b['id'] ?? null,
                'internalBatchNumber' => $b['internal_batch_number'] ?? null,
                'batchNumber' => $b['batch_number'] ?? null,
                'expiryDate' => $b['expiry_date'] ?? null,
                'quantity' => $b['quantity'] ?? 0,
                'reserved' => $b['reserved'] ?? 0,
                'available' => $b['available'] ?? 0,
            ], $rawBatches),
        ];
    }

    private static function stockState(array $item): string
    {
        $current = (float) ($item['current_stock'] ?? 0);
        $reorder = (float) ($item['reorder_level'] ?? 0);

        if ($current <= 0) {
            return 'out_of_stock';
        }

        if ($current <= $reorder) {
            return 'low_stock';
        }

        return 'healthy';
    }
}
