<?php

namespace App\Modules\Staff\Domain\ValueObjects;

enum StaffPracticeAuthorityLevel: string
{
    case INDEPENDENT = 'independent';
    case SUPERVISED = 'supervised';
    case TRAINING_ONLY = 'training_only';
    case NOT_AUTHORIZED = 'not_authorized';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::cases());
    }
}
