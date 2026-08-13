<?php

namespace App\Modules\Patient\Domain\ValueObjects;

class PatientName
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        if ($value === '') {
            return '';
        }

        return implode(' ', array_map(
            static fn (string $word): string => self::normalizeWord($word),
            explode(' ', $value),
        ));
    }

    public static function formatDisplayName(?string $firstName, ?string $middleName, ?string $lastName): string
    {
        $parts = array_values(array_filter(
            [$firstName, $middleName, $lastName],
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        ));

        $name = $parts !== [] ? implode(' ', $parts) : '';

        return mb_strtoupper($name !== '' ? $name : 'Unknown patient');
    }

    private static function normalizeWord(string $word): string
    {
        if ($word === '') {
            return '';
        }

        if (str_contains($word, '-')) {
            return implode('-', array_map(
                static fn (string $part): string => self::titleCase($part),
                explode('-', $word),
            ));
        }

        if (str_contains($word, "'")) {
            return implode("'", array_map(
                static fn (string $part): string => self::titleCase($part),
                explode("'", $word),
            ));
        }

        return self::titleCase($word);
    }

    private static function titleCase(string $word): string
    {
        return mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
    }
}
