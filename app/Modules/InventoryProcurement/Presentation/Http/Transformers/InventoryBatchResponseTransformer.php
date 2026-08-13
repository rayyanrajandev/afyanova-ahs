<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Transformers;

class InventoryBatchResponseTransformer
{
    public static function transform(array $batch): array
    {
        return [
            'id' => $batch['id'] ?? null,
            'itemId' => $batch['item_id'] ?? null,
            'internalBatchNumber' => $batch['internal_batch_number'] ?? null,
            'batchNumber' => $batch['batch_number'] ?? null,
            'lotNumber' => $batch['lot_number'] ?? null,
            'manufactureDate' => $batch['manufacture_date'] ?? null,
            'expiryDate' => $batch['expiry_date'] ?? null,
            'quantity' => $batch['quantity'] ?? null,
            'reservedQuantity' => $batch['reserved_quantity'] ?? null,
            'availableQuantity' => $batch['available_quantity'] ?? null,
            'warehouseId' => $batch['warehouse_id'] ?? null,
            'binLocation' => $batch['bin_location'] ?? null,
            'supplierId' => $batch['supplier_id'] ?? null,
            'manufacturer' => $batch['manufacturer'] ?? null,
            'unitCost' => $batch['unit_cost'] ?? null,
            'status' => $batch['status'] ?? null,
            'expiryState' => self::expiryState($batch),
            'notes' => $batch['notes'] ?? null,
            'createdAt' => $batch['created_at'] ?? null,
            'updatedAt' => $batch['updated_at'] ?? null,
        ];
    }

    private static function expiryState(array $batch): ?string
    {
        $expiryDate = $batch['expiry_date'] ?? null;
        if ($expiryDate === null) {
            return null;
        }

        $expiry = is_string($expiryDate) ? new \DateTimeImmutable($expiryDate) : $expiryDate;
        $now = new \DateTimeImmutable();

        if ($expiry <= $now) {
            return 'expired';
        }

        $daysUntilExpiry = (int) $now->diff($expiry)->days;

        if ($daysUntilExpiry <= 30) {
            return 'critical';
        }

        if ($daysUntilExpiry <= 90) {
            return 'warning';
        }

        return 'healthy';
    }
}
