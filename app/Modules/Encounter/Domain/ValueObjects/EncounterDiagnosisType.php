<?php

namespace App\Modules\Encounter\Domain\ValueObjects;

enum EncounterDiagnosisType: string
{
    case PRIMARY = 'primary';
    case SECONDARY = 'secondary';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
