<?php

namespace App\Modules\InventoryProcurement\Domain\ValueObjects;

enum InventoryStockMovementType: string
{
    case RECEIVE = 'receive';
    case ISSUE = 'issue';
    case ADJUST = 'adjust';
    case TRANSFER = 'transfer';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
