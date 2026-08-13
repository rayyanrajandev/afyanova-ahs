<?php

namespace App\Modules\MedicalRecord\Domain\ValueObjects;

enum MedicalRecordStatus: string
{
    case DRAFT = 'draft';
    case FINALIZED = 'finalized';
    case AMENDED = 'amended';
    case ARCHIVED = 'archived';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
