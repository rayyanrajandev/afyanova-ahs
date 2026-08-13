<?php

namespace App\Modules\InventoryProcurement\Domain\ValueObjects;

enum InventoryWarehouseStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

