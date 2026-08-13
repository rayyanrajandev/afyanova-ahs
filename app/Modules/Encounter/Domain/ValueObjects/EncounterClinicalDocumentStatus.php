<?php

namespace App\Modules\Encounter\Domain\ValueObjects;

enum EncounterClinicalDocumentStatus: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
