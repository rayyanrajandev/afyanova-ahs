<?php

namespace App\Modules\Encounter\Domain\ValueObjects;

enum EncounterType: string
{
    case OUTPATIENT = 'outpatient';
    case EMERGENCY = 'emergency';
    case INPATIENT = 'inpatient';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
