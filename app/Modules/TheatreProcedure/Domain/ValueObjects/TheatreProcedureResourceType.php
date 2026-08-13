<?php

namespace App\Modules\TheatreProcedure\Domain\ValueObjects;

enum TheatreProcedureResourceType: string
{
    case ROOM = 'room';
    case STAFF = 'staff';
    case EQUIPMENT = 'equipment';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $resourceType): string => $resourceType->value,
            self::cases(),
        );
    }
}
