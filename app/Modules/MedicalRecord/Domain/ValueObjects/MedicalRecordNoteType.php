<?php

namespace App\Modules\MedicalRecord\Domain\ValueObjects;

enum MedicalRecordNoteType: string
{
    case CONSULTATION_NOTE = 'consultation_note';
    case ADMISSION_NOTE = 'admission_note';
    case PROGRESS_NOTE = 'progress_note';
    case DISCHARGE_NOTE = 'discharge_note';
    case REFERRAL_NOTE = 'referral_note';
    case NURSING_NOTE = 'nursing_note';
    case PROCEDURE_NOTE = 'procedure_note';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && in_array($value, self::values(), true);
    }
}
