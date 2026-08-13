<?php

namespace App\Modules\Staff\Domain\ValueObjects;

enum StaffSupervisionLevel: string
{
    case INDEPENDENT = 'independent';
    case INDIRECT_SUPERVISION = 'indirect_supervision';
    case DIRECT_SUPERVISION = 'direct_supervision';
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
