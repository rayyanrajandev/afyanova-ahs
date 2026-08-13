<?php

namespace App\Modules\EmergencyTriage\Domain\ValueObjects;

enum EmergencyTriageCaseStatus: string
{
    case WAITING = 'waiting';
    case TRIAGED = 'triaged';
    case IN_TREATMENT = 'in_treatment';
    case ADMITTED = 'admitted';
    case DISCHARGED = 'discharged';
    case CANCELLED = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
