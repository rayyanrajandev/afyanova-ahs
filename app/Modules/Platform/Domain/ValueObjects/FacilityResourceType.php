<?php

namespace App\Modules\Platform\Domain\ValueObjects;

enum FacilityResourceType: string
{
    case SERVICE_POINT = 'service_point';
    case WARD_BED = 'ward_bed';
    case OBSERVATION_ROOM = 'observation_room';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}

