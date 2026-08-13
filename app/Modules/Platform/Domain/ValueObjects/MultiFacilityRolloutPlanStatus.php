<?php

namespace App\Modules\Platform\Domain\ValueObjects;

enum MultiFacilityRolloutPlanStatus: string
{
    case DRAFT = 'draft';
    case READY = 'ready';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case ROLLED_BACK = 'rolled_back';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
