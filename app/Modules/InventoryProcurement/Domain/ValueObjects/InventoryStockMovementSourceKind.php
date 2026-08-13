<?php

namespace App\Modules\InventoryProcurement\Domain\ValueObjects;

enum InventoryStockMovementSourceKind: string
{
    case CLINICAL_CONSUMPTION = 'clinical_consumption';
    case PROCUREMENT_RECEIPT = 'procurement_receipt';
    case WAREHOUSE_TRANSFER = 'warehouse_transfer';
    case STOCK_RECONCILIATION = 'stock_reconciliation';
    case MANUAL_ENTRY = 'manual_entry';
    case SYSTEM_GENERATED = 'system_generated';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
