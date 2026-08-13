<?php

namespace App\Modules\InventoryProcurement\Domain\ValueObjects;

enum InventoryWarehouseTransferVarianceReviewStatus: string
{
    case PENDING = 'pending';
    case REVIEWED = 'reviewed';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
