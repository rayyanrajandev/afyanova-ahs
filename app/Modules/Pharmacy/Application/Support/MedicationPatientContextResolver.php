<?php

namespace App\Modules\Pharmacy\Application\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class MedicationPatientContextResolver
{
    /**
     * @param array<string, mixed>|null $patient
     * @param array<string, mixed>|null $appointment
     * @return array{
     *     age_years:int|null,
     *     age_months:int|null,
     *     weight_kg:float|null,
     *     weight_source:string|null
     * }
     */
    public static function resolve(?array $patient, ?array $appointment = null): array
    {
        $dateOfBirth = $patient['date_of_birth'] ?? null;
        $weightKg = self::extractWeightKg($appointment['triage_vitals_summary'] ?? null);

        return [
            'age_years' => self::resolveAgeYears($dateOfBirth),
            'age_months' => self::resolveAgeMonths($dateOfBirth),
            'weight_kg' => $weightKg,
            'weight_source' => $weightKg !== null ? 'appointment_triage_vitals' : null,
        ];
    }

    private static function resolveAgeYears(mixed $dateOfBirth): ?int
    {
        $dob = self::parseDateOfBirth($dateOfBirth);
        if ($dob === null) {
            return null;
        }

        return $dob->diffInYears(CarbonImmutable::now());
    }

    private static function resolveAgeMonths(mixed $dateOfBirth): ?int
    {
        $dob = self::parseDateOfBirth($dateOfBirth);
        if ($dob === null) {
            return null;
        }

        return $dob->diffInMonths(CarbonImmutable::now());
    }

    private static function parseDateOfBirth(mixed $dateOfBirth): ?CarbonImmutable
    {
        if ($dateOfBirth instanceof DateTimeInterface) {
            $dob = CarbonImmutable::instance($dateOfBirth);

            return $dob->isFuture() ? null : $dob;
        }

        $normalizedDateOfBirth = trim((string) ($dateOfBirth ?? ''));
        if ($normalizedDateOfBirth === '') {
            return null;
        }

        try {
            $dob = CarbonImmutable::parse($normalizedDateOfBirth);
        } catch (Throwable) {
            return null;
        }

        return $dob->isFuture() ? null : $dob;
    }

    private static function extractWeightKg(mixed $triageVitalsSummary): ?float
    {
        $normalizedSummary = trim((string) ($triageVitalsSummary ?? ''));
        if ($normalizedSummary === '') {
            return null;
        }

        $patterns = [
            '/\bweight\s*[:=-]?\s*(\d+(?:\.\d+)?)\s*kg\b/i',
            '/\bwt\.?\s*[:=-]?\s*(\d+(?:\.\d+)?)\s*kg\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedSummary, $matches) !== 1) {
                continue;
            }

            $weightKg = isset($matches[1]) && is_numeric($matches[1]) ? (float) $matches[1] : null;
            if ($weightKg === null || $weightKg <= 0 || $weightKg > 500) {
                return null;
            }

            return round($weightKg, 2);
        }

        return null;
    }
}
