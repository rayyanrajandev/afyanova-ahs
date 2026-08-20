<?php

namespace App\Modules\Pharmacy\Application\Support;

class MedicationAllergySafetyKnowledgeBase
{
    /**
     * @param array<int, array<string, mixed>> $activeAllergies
     * @param string|null $medicationCode
     * @param string|null $medicationName
     * @return array<int, array<string, mixed>>
     */
    public static function detectConflicts(
        array $activeAllergies,
        ?string $medicationCode,
        ?string $medicationName
    ): array {
        $conflicts = [];
        $normalizedMedicationCode = mb_strtolower(trim((string) $medicationCode));
        $normalizedMedicationName = mb_strtolower(trim((string) $medicationName));

        if ($normalizedMedicationCode === '' && $normalizedMedicationName === '') {
            return $conflicts;
        }

        foreach ($activeAllergies as $allergy) {
            $normalizedAllergyCode = mb_strtolower(trim((string) ($allergy['substance_code'] ?? '')));
            $normalizedAllergyName = mb_strtolower(trim((string) ($allergy['substance_name'] ?? '')));

            if (self::matchesCode($normalizedAllergyCode, $normalizedMedicationCode)
                || self::matchesName($normalizedAllergyName, $normalizedMedicationName)
                || self::matchesCrossSensitivity($normalizedAllergyName, $normalizedMedicationName)
            ) {
                $conflicts[] = $allergy;
            }
        }

        return $conflicts;
    }

    private static function matchesCode(string $allergyCode, string $medicationCode): bool
    {
        if ($allergyCode === '' || $medicationCode === '') {
            return false;
        }

        return $allergyCode === $medicationCode;
    }

    private static function matchesName(string $allergyName, string $medicationName): bool
    {
        if ($allergyName === '' || $medicationName === '') {
            return false;
        }

        return str_contains($medicationName, $allergyName) || str_contains($allergyName, $medicationName);
    }

    private static function matchesCrossSensitivity(string $allergyName, string $medicationName): bool
    {
        // This is a stub for a proper CDS cross-sensitivity knowledge base.
        // It maps a known allergy class to common medication substrings.
        
        $classes = [
            'penicillin' => ['amoxicillin', 'ampicillin', 'piperacillin', 'ticarcillin', 'penicillin', 'augmentin'],
            'sulfa' => ['sulfamethoxazole', 'bactrim', 'septra', 'sulfasalazine', 'sulfadiazine'],
            'nsaid' => ['ibuprofen', 'naproxen', 'diclofenac', 'celecoxib', 'meloxicam', 'ketorolac', 'aspirin'],
        ];

        foreach ($classes as $allergyClass => $medications) {
            if (str_contains($allergyName, $allergyClass)) {
                foreach ($medications as $medication) {
                    if (str_contains($medicationName, $medication)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
