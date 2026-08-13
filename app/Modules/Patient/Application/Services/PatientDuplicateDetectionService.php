<?php

namespace App\Modules\Patient\Application\Services;

use App\Modules\Patient\Application\Exceptions\DuplicatePatientException;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Patient\Domain\ValueObjects\PatientPhoneNumber;

class PatientDuplicateDetectionService
{
    private const STRONG_WARNING_THRESHOLD = 80;

    private const POSSIBLE_WARNING_THRESHOLD = 50;

    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws DuplicatePatientException
     */
    public function evaluate(array $patient, ?string $excludePatientId = null): array
    {
        $identity = $this->extractIdentity($patient);

        $hardDuplicates = $this->patientRepository->findActiveHardDuplicateIdentifiers(
            nationalId: $this->nullableString($identity['national_id'] ?? null),
            patientNumber: $this->nullableString($identity['patient_number'] ?? null),
            excludePatientId: $excludePatientId,
        );

        if ($hardDuplicates !== []) {
            throw new DuplicatePatientException($this->formatDuplicates($hardDuplicates, 'hard_block'));
        }

        $candidates = $this->patientRepository->findActiveDuplicateCandidates(
            firstName: $this->nullableString($identity['first_name'] ?? null),
            lastName: $this->nullableString($identity['last_name'] ?? null),
            dateOfBirth: $this->nullableString($identity['date_of_birth'] ?? null),
            phone: $this->nullableString($identity['phone'] ?? null),
            gender: $this->nullableString($identity['gender'] ?? null),
            addressLine: $this->nullableString($identity['address_line'] ?? null),
            excludePatientId: $excludePatientId,
        );

        $warnings = [];
        foreach ($candidates as $candidate) {
            $score = $this->score($identity, $candidate);
            if ($score < self::POSSIBLE_WARNING_THRESHOLD) {
                continue;
            }

            $warnings[] = [
                ...$this->formatDuplicate($candidate, $score >= self::STRONG_WARNING_THRESHOLD ? 'strong_warning' : 'possible_warning'),
                'code' => $score >= self::STRONG_WARNING_THRESHOLD ? 'patient.duplicate.strong' : 'patient.duplicate.possible',
                'message' => $score >= self::STRONG_WARNING_THRESHOLD
                    ? 'Strong possible duplicate found. Registration was allowed because no hard identifier matched.'
                    : 'Possible duplicate found. Registration was allowed for staff review because no hard identifier matched.',
                'duplicateConfidence' => $score,
                'duplicateConfidenceLabel' => $score >= self::STRONG_WARNING_THRESHOLD ? 'strong' : 'possible',
                'matchedFields' => $this->matchedFields($identity, $candidate),
            ];
        }

        usort(
            $warnings,
            static fn (array $left, array $right): int => ($right['duplicateConfidence'] ?? 0) <=> ($left['duplicateConfidence'] ?? 0),
        );

        return array_slice($warnings, 0, 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractIdentity(array $patient): array
    {
        return [
            'patient_number' => $patient['patient_number'] ?? null,
            'first_name' => $patient['first_name'] ?? null,
            'last_name' => $patient['last_name'] ?? null,
            'date_of_birth' => $patient['date_of_birth'] ?? null,
            'phone' => $patient['phone'] ?? null,
            'gender' => $patient['gender'] ?? null,
            'national_id' => $patient['national_id'] ?? null,
            'address_line' => $patient['address_line'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $duplicates
     * @return array<int, array<string, mixed>>
     */
    private function formatDuplicates(array $duplicates, string $matchType): array
    {
        return array_map(fn (array $duplicate): array => $this->formatDuplicate($duplicate, $matchType), $duplicates);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDuplicate(array $duplicate, string $matchType): array
    {
        return [
            'id' => $duplicate['id'] ?? null,
            'patientNumber' => $duplicate['patient_number'] ?? null,
            'firstName' => $duplicate['first_name'] ?? null,
            'lastName' => $duplicate['last_name'] ?? null,
            'dateOfBirth' => $duplicate['date_of_birth'] ?? null,
            'phone' => $duplicate['phone'] ?? null,
            'gender' => $duplicate['gender'] ?? null,
            'nationalId' => $duplicate['national_id'] ?? null,
            'countryCode' => $duplicate['country_code'] ?? null,
            'region' => $duplicate['region'] ?? null,
            'district' => $duplicate['district'] ?? null,
            'addressLine' => $duplicate['address_line'] ?? null,
            'status' => $duplicate['status'] ?? null,
            'createdAt' => $duplicate['created_at'] ?? null,
            'duplicateMatchType' => $matchType,
        ];
    }

    private function score(array $incoming, array $candidate): int
    {
        $score = 0;

        if ($this->sameText($incoming['first_name'] ?? null, $candidate['first_name'] ?? null)) {
            $score += 20;
        }

        if ($this->sameText($incoming['last_name'] ?? null, $candidate['last_name'] ?? null)) {
            $score += 20;
        }

        if ($this->sameDate($incoming['date_of_birth'] ?? null, $candidate['date_of_birth'] ?? null)) {
            $score += 30;
        }

        if ($this->samePhone($incoming['phone'] ?? null, $candidate['phone'] ?? null)) {
            $score += 15;
        }

        if ($this->sameText($incoming['gender'] ?? null, $candidate['gender'] ?? null)) {
            $score += 10;
        }

        if ($this->sameText($incoming['address_line'] ?? null, $candidate['address_line'] ?? null)) {
            $score += 10;
        }

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function matchedFields(array $incoming, array $candidate): array
    {
        $matches = [];

        foreach ([
            'first_name' => 'firstName',
            'last_name' => 'lastName',
            'gender' => 'gender',
            'address_line' => 'addressLine',
        ] as $storageKey => $apiKey) {
            if ($this->sameText($incoming[$storageKey] ?? null, $candidate[$storageKey] ?? null)) {
                $matches[] = $apiKey;
            }
        }

        if ($this->sameDate($incoming['date_of_birth'] ?? null, $candidate['date_of_birth'] ?? null)) {
            $matches[] = 'dateOfBirth';
        }

        if ($this->samePhone($incoming['phone'] ?? null, $candidate['phone'] ?? null)) {
            $matches[] = 'phone';
        }

        return $matches;
    }

    private function sameText(mixed $left, mixed $right): bool
    {
        $leftNormalized = $this->normalizeText($left);
        $rightNormalized = $this->normalizeText($right);

        return $leftNormalized !== '' && $rightNormalized !== '' && $leftNormalized === $rightNormalized;
    }

    private function sameDate(mixed $left, mixed $right): bool
    {
        $leftNormalized = substr(trim((string) $left), 0, 10);
        $rightNormalized = substr(trim((string) $right), 0, 10);

        return $leftNormalized !== '' && $rightNormalized !== '' && $leftNormalized === $rightNormalized;
    }

    private function samePhone(mixed $left, mixed $right): bool
    {
        $leftNormalized = $this->normalizePhone($left);
        $rightNormalized = $this->normalizePhone($right);

        return $leftNormalized !== '' && $rightNormalized !== '' && $leftNormalized === $rightNormalized;
    }

    private function normalizeText(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $value))) ?? '';
    }

    private function normalizePhone(mixed $value): string
    {
        return PatientPhoneNumber::normalize($value);
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
