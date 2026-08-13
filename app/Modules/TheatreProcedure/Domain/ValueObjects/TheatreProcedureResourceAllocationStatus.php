<?php

namespace App\Modules\TheatreProcedure\Domain\ValueObjects;

enum TheatreProcedureResourceAllocationStatus: string
{
    case SCHEDULED = 'scheduled';
    case IN_USE = 'in_use';
    case RELEASED = 'released';
    case CANCELLED = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function overlapBlockingValues(): array
    {
        return [
            self::SCHEDULED->value,
            self::IN_USE->value,
        ];
    }
}
