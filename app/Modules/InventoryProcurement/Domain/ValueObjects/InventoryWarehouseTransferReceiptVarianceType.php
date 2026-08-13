<?php

namespace App\Modules\InventoryProcurement\Domain\ValueObjects;

enum InventoryWarehouseTransferReceiptVarianceType: string
{
    case FULL = 'full';
    case SHORT = 'short';
    case DAMAGED = 'damaged';
    case WRONG_BATCH = 'wrong_batch';
    case EXCESS = 'excess';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
