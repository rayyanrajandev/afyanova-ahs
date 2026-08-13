<?php

namespace App\Modules\Platform\Domain\ValueObjects;

enum MultiFacilityRolloutIncidentStatus: string
{
    case OPEN = 'open';
    case MITIGATING = 'mitigating';
    case RESOLVED = 'resolved';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
