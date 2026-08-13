<?php

namespace App\Modules\Pos\Domain\ValueObjects;

enum PosSaleChannel: string
{
    case GENERAL_RETAIL = 'general_retail';
    case PHARMACY_OTC = 'pharmacy_otc';
    case CAFETERIA = 'cafeteria';
    case FRONTDESK_QUICK = 'frontdesk_quick';
    case LAB_QUICK = 'lab_quick';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $channel): string => $channel->value, self::cases());
    }
}
