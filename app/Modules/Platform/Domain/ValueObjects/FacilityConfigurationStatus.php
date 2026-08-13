<?php

namespace App\Modules\Platform\Domain\ValueObjects;

enum FacilityConfigurationStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
