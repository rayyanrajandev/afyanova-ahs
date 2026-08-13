<?php

namespace App\Support\Documents;

class DocumentViewFormatter
{
    public static function money(mixed $value, ?string $currencyCode): string
    {
        $amount = self::number($value);
        $currency = strtoupper(trim((string) ($currencyCode ?: 'TZS')));
        $currency = $currency !== '' ? $currency : 'TZS';

        return sprintf('%s %s', $currency, number_format($amount, 2));
    }

    public static function date(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'N/A';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? 'N/A' : date('M j, Y', $timestamp);
    }

    public static function dateTime(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'N/A';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? 'N/A' : date('M j, Y g:i A', $timestamp);
    }

    public static function enum(?string $value): string
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return 'N/A';
        }

        return ucwords(str_replace('_', ' ', $normalized));
    }

    /**
     * @return array<int, string>
     */
    public static function textBlocks(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $normalized = preg_replace('/<\/(p|div|li|blockquote|h[1-6])>/i', "\n", $value);
        $normalized = preg_replace('/<br\s*\/?>/i', "\n", (string) $normalized);
        $normalized = strip_tags((string) $normalized);

        $lines = preg_split('/\r\n|\r|\n/', (string) $normalized) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $line): ?string => ($trimmed = trim(preg_replace('/\s+/', ' ', $line) ?: '')) !== '' ? $trimmed : null,
            $lines,
        )));
    }

    public static function statusCounts(array $counts): string
    {
        $segments = [];

        foreach ($counts as $key => $value) {
            if (! is_string($key) || in_array($key, ['total', 'other'], true) || (int) $value <= 0) {
                continue;
            }

            $segments[] = sprintf('%s %d', self::enum($key), (int) $value);
        }

        return $segments !== [] ? implode(' | ', $segments) : 'No active status distribution.';
    }

    private static function number(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite($value) ? (float) $value : 0.0;
        }

        $parsed = (float) $value;

        return is_finite($parsed) ? $parsed : 0.0;
    }
}
