<?php

namespace App\Modules\Encounter\Domain\ValueObjects;

enum EncounterDisposition: string
{
    case DISCHARGED = 'discharged';
    case ADMITTED = 'admitted';
    case TRANSFERRED = 'transferred';
    case REFERRED = 'referred';
    case DECEASED = 'deceased';
    case LEFT_AGAINST_MEDICAL_ADVICE = 'left_against_medical_advice';
    case OTHER = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $disposition): string => $disposition->value, self::cases());
    }
}
