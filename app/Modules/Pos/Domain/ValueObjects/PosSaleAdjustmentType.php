<?php

namespace App\Modules\Pos\Domain\ValueObjects;

enum PosSaleAdjustmentType: string
{
    case VOID = 'void';
    case REFUND = 'refund';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
