<?php

namespace App\Modules\Pos\Domain\ValueObjects;

enum PosRegisterSessionStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
