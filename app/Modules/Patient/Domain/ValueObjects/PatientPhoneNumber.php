<?php

namespace App\Modules\Patient\Domain\ValueObjects;

/**
 * Tanzania-style phone normalization, shared between duplicate detection
 * (PatientDuplicateDetectionService) and the `phone_normalized` column
 * (populated on write, searched on read) — one implementation, not two
 * that could silently drift apart.
 */
class PatientPhoneNumber
{
    public static function normalize(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '255')) {
            return $digits;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '255'.substr($digits, 1);
        }

        if (strlen($digits) === 9) {
            return '255'.$digits;
        }

        return $digits;
    }
}
