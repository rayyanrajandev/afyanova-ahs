<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Transformers;

use App\Modules\InventoryProcurement\Presentation\Support\InventoryStockMovementSourcePresenter;

class InventoryStockMovementResponseTransformer
{
    public static function transform(array $movement): array
    {
        $metadata = isset($movement['metadata']) && is_array($movement['metadata'])
            ? $movement['metadata']
            : null;
        $reconciliation = null;

        if (($metadata['source'] ?? null) === 'stock_reconciliation') {
            $reconciliation = [
                'expectedStock' => $metadata['expectedStock'] ?? null,
                'countedStock' => $metadata['countedStock'] ?? null,
                'varianceQuantity' => $metadata['varianceQuantity'] ?? null,
                'sessionReference' => $metadata['sessionReference'] ?? null,
            ];
        }

        $source = InventoryStockMovementSourcePresenter::describe($movement);

        return [
            'id' => $movement['id'] ?? null,
            'itemId' => $movement['item_id'] ?? null,
            'batchId' => $movement['batch_id'] ?? null,
            'procurementRequestId' => $movement['procurement_request_id'] ?? null,
            'sourceSupplierId' => $movement['source_supplier_id'] ?? null,
            'sourceWarehouseId' => $movement['source_warehouse_id'] ?? null,
            'destinationWarehouseId' => $movement['destination_warehouse_id'] ?? null,
            'destinationDepartmentId' => $movement['destination_department_id'] ?? null,
            'sourceType' => $movement['source_type'] ?? null,
            'sourceId' => $movement['source_id'] ?? null,
            'sourceKey' => $source['key'],
            'sourceLabel' => $source['label'],
            'sourceReference' => $source['reference'],
            'sourceDetail' => $source['detail'],
            'clinicalCatalogItemId' => $movement['clinical_catalog_item_id'] ?? null,
            'consumptionRecipeItemId' => $movement['consumption_recipe_item_id'] ?? null,
            'movementType' => $movement['movement_type'] ?? null,
            'adjustmentDirection' => $movement['adjustment_direction'] ?? null,
            'isOpeningStock' => (bool) ($movement['is_opening_stock'] ?? false),
            'quantity' => $movement['quantity'] ?? null,
            'quantityDelta' => $movement['quantity_delta'] ?? null,
            'stockBefore' => $movement['stock_before'] ?? null,
            'stockAfter' => $movement['stock_after'] ?? null,
            'reason' => $movement['reason'] ?? null,
            'reasonCode' => $movement['reason_code'] ?? null,
            'notes' => $movement['notes'] ?? null,
            'actorId' => $movement['actor_id'] ?? null,
            'metadata' => $metadata,
            'reconciliation' => $reconciliation,
            'occurredAt' => $movement['occurred_at'] ?? null,
            'createdAt' => $movement['created_at'] ?? null,
            'item' => isset($movement['item']) && is_array($movement['item'])
                ? InventoryItemResponseTransformer::transform($movement['item'])
                : null,
        ];
    }
}
