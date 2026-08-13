<?php

namespace App\Modules\Pos\Domain\ValueObjects;

enum PosSaleLineType: string
{
    case MANUAL = 'manual';
    case RETAIL_ITEM = 'retail_item';
    case PHARMACY_ITEM = 'pharmacy_item';
    case CAFETERIA_ITEM = 'cafeteria_item';
    case SERVICE = 'service';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
