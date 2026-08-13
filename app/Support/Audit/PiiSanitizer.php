<?php

namespace App\Support\Audit;

class PiiSanitizer
{
    private const PII_PATTERNS = [
        '/\b\d{3}-\d{2}-\d{4}\b/',           // SSN: 123-45-6789
        '/\b\d{9}\b/',                          // SSN (no dashes): 123456789
        '/[A-Z]{2}\d{6}/',                     // Passport numbers (simplified)
        '/\b\d{10}\b/',                         // Phone numbers (10-digit)
        '/\b[\w\.-]+@[\w\.-]+\.\w{2,}\b/',    // Email addresses
        '/\b\d{16}\b/',                         // Credit card numbers (16-digit)
        '/\b\d{4}-\d{4}-\d{4}-\d{4}\b/',       // Credit card (with dashes)
        '/\bMRN-\d+\b/i',                      // Medical record numbers (MRN-xxxxx)
        '/\bpatient-\d+\b/i',                   // Patient identifiers (patient-xxxxx)
    ];

    private const PII_REPLACEMENT = '[REDACTED]';

    /**
     * Sanitize a string by masking PII patterns
     */
    public function sanitizeString(string $value): string
    {
        return preg_replace(self::PII_PATTERNS, self::PII_REPLACEMENT, $value) ?? $value;
    }

    /**
     * Sanitize an array recursively, masking PII values at specified keys
     *
     * @param array<mixed> $data
     * @param array<int, string> $sensitiveKeys Keys whose values should always be masked
     * @return array<mixed>
     */
    public function sanitizeArray(array $data, array $sensitiveKeys = []): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $sensitiveKeys, true)) {
                $result[$key] = self::PII_REPLACEMENT;
                continue;
            }

            if (is_string($value)) {
                $result[$key] = $this->sanitizeString($value);
            } elseif (is_array($value)) {
                $result[$key] = $this->sanitizeArray($value, $sensitiveKeys);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Sanitize user-provided free text (highest PII risk)
     */
    public function sanitizeFreeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return $this->sanitizeString($text);
    }
}
