<?php

namespace App\Modules\Platform\Domain\ValueObjects;

enum MultiFacilityRolloutCheckpointStatus: string
{
    case NOT_STARTED = 'not_started';
    case IN_PROGRESS = 'in_progress';
    case BLOCKED = 'blocked';
    case PASSED = 'passed';
    case FAILED = 'failed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
