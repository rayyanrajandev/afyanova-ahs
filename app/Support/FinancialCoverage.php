<?php

namespace App\Support;

final class FinancialCoverage
{
    public const SELF_PAY = 'self_pay';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::SELF_PAY,
            'insurance',
            'employer',
            'government',
            'donor',
            'other',
        ];
    }

    public static function normalize(?string $value, ?string $fallback = self::SELF_PAY): ?string
    {
        $normalized = strtolower(trim((string) $value));

        if ($normalized !== '' && in_array($normalized, self::values(), true)) {
            return $normalized;
        }

        if ($fallback === null) {
            return null;
        }

        $normalizedFallback = strtolower(trim($fallback));

        return in_array($normalizedFallback, self::values(), true)
            ? $normalizedFallback
            : self::SELF_PAY;
    }

    public static function isThirdParty(?string $value): bool
    {
        $normalized = self::normalize($value, null);

        return $normalized !== null && $normalized !== self::SELF_PAY;
    }
}
