<?php

namespace App\Modules\Pharmacy\Application\Support;

final class MedicationInteractionKnowledgeBase
{
    /**
     * @param array<int, array<string, mixed>> $contextEntries
     * @return list<array{
     *     rule_code:string,
     *     severity:'warning'|'critical',
     *     category:string,
     *     message:string,
     *     clinical_effect:string,
     *     recommended_action:string,
     *     target_medication_code:string|null,
     *     target_medication_name:string|null,
     *     interacting_medication_code:string|null,
     *     interacting_medication_name:string|null,
     *     source_type:string,
     *     source_label:string
     * }>
     */
    public static function detectConflicts(
        ?string $targetMedicationCode,
        ?string $targetMedicationName,
        array $contextEntries,
    ): array {
        $normalizedTargetCode = self::normalize($targetMedicationCode);
        $normalizedTargetName = self::normalize($targetMedicationName);

        if ($normalizedTargetCode === '' && $normalizedTargetName === '') {
            return [];
        }

        $conflicts = [];
        $seen = [];

        foreach ($contextEntries as $entry) {
            $entryCode = self::normalize($entry['medication_code'] ?? null);
            $entryName = self::normalize($entry['medication_name'] ?? null);

            if ($entryCode === '' && $entryName === '') {
                continue;
            }

            $rule = self::matchRule(
                targetMedicationCode: $normalizedTargetCode,
                targetMedicationName: $normalizedTargetName,
                contextMedicationCode: $entryCode,
                contextMedicationName: $entryName,
            );

            if ($rule === null) {
                continue;
            }

            $sourceType = trim((string) ($entry['source_type'] ?? 'unknown'));
            $sourceLabel = trim((string) ($entry['source_label'] ?? 'Current therapy'));
            $interactingMedicationCode = self::nullableTrim($entry['medication_code'] ?? null);
            $interactingMedicationName = self::nullableTrim($entry['medication_name'] ?? null);
            $dedupeKey = implode('|', [
                $rule['code'],
                self::normalize($interactingMedicationCode ?? $interactingMedicationName ?? ''),
                self::normalize($sourceType),
            ]);

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $targetMedicationLabel = self::displayMedicationLabel(
                code: $targetMedicationCode,
                name: $targetMedicationName,
                fallback: $rule['target_label'],
            );
            $interactingMedicationLabel = self::displayMedicationLabel(
                code: $interactingMedicationCode,
                name: $interactingMedicationName,
                fallback: $rule['context_label'],
            );

            $conflicts[] = [
                'rule_code' => $rule['code'],
                'severity' => $rule['severity'],
                'category' => 'interaction',
                'message' => sprintf(
                    '%s interacts with %s from %s. %s',
                    $targetMedicationLabel,
                    $interactingMedicationLabel,
                    $sourceLabel,
                    $rule['summary'],
                ),
                'clinical_effect' => $rule['clinical_effect'],
                'recommended_action' => $rule['recommended_action'],
                'target_medication_code' => self::nullableTrim($targetMedicationCode),
                'target_medication_name' => self::nullableTrim($targetMedicationName),
                'interacting_medication_code' => $interactingMedicationCode,
                'interacting_medication_name' => $interactingMedicationName,
                'source_type' => $sourceType !== '' ? $sourceType : 'unknown',
                'source_label' => $sourceLabel !== '' ? $sourceLabel : 'Current therapy',
            ];

            $seen[$dedupeKey] = true;
        }

        return $conflicts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function rules(): array
    {
        return [
            [
                'code' => 'interaction_ibuprofen_furosemide',
                'severity' => 'warning',
                'left' => ['codes' => ['atc:m01ae01'], 'names' => ['ibuprofen']],
                'right' => ['codes' => ['atc:c03ca01'], 'names' => ['furosemide']],
                'left_label' => 'Ibuprofen',
                'right_label' => 'Furosemide',
                'summary' => 'This combination can reduce diuretic response and increase renal stress.',
                'clinical_effect' => 'Reduced diuretic effect with added renal-risk pressure.',
                'recommended_action' => 'Confirm hydration, renal-risk context, and whether a non-NSAID option is safer.',
            ],
            [
                'code' => 'interaction_ibuprofen_amlodipine',
                'severity' => 'warning',
                'left' => ['codes' => ['atc:m01ae01'], 'names' => ['ibuprofen']],
                'right' => ['codes' => ['atc:c08ca01'], 'names' => ['amlodipine']],
                'left_label' => 'Ibuprofen',
                'right_label' => 'Amlodipine',
                'summary' => 'NSAID therapy may blunt blood-pressure control and add renal risk.',
                'clinical_effect' => 'Potential loss of blood-pressure control and added renal stress.',
                'recommended_action' => 'Review blood-pressure history and consider whether a non-NSAID option is more appropriate.',
            ],
            [
                'code' => 'interaction_ibuprofen_enalapril',
                'severity' => 'warning',
                'left' => ['codes' => ['atc:m01ae01'], 'names' => ['ibuprofen']],
                'right' => ['codes' => ['atc:c09aa02'], 'names' => ['enalapril']],
                'left_label' => 'Ibuprofen',
                'right_label' => 'Enalapril',
                'summary' => 'NSAID therapy may reduce ACE-inhibitor effect and increase renal risk.',
                'clinical_effect' => 'Potential loss of blood-pressure control with renal-risk amplification.',
                'recommended_action' => 'Review renal status and blood-pressure control before continuing this combination.',
            ],
            [
                'code' => 'interaction_diclofenac_furosemide',
                'severity' => 'warning',
                'left' => ['codes' => ['atc:m01ab05'], 'names' => ['diclofenac']],
                'right' => ['codes' => ['atc:c03ca01'], 'names' => ['furosemide']],
                'left_label' => 'Diclofenac',
                'right_label' => 'Furosemide',
                'summary' => 'This combination can reduce diuretic response and increase renal-risk pressure.',
                'clinical_effect' => 'Potential loss of diuretic effect with added fluid-retention and renal-risk concerns.',
                'recommended_action' => 'Review fluid status, renal results, and whether a non-NSAID analgesic is safer before continuing.',
            ],
            [
                'code' => 'interaction_diclofenac_enalapril',
                'severity' => 'warning',
                'left' => ['codes' => ['atc:m01ab05'], 'names' => ['diclofenac']],
                'right' => ['codes' => ['atc:c09aa02'], 'names' => ['enalapril']],
                'left_label' => 'Diclofenac',
                'right_label' => 'Enalapril',
                'summary' => 'NSAID therapy may reduce ACE-inhibitor effect and increase renal-risk pressure.',
                'clinical_effect' => 'Potential blood-pressure destabilization with additive renal stress.',
                'recommended_action' => 'Confirm renal context, blood-pressure control, and whether a non-NSAID option is more appropriate.',
            ],
            [
                'code' => 'interaction_enalapril_spironolactone',
                'severity' => 'warning',
                'left' => ['codes' => ['atc:c09aa02'], 'names' => ['enalapril']],
                'right' => ['codes' => ['atc:c03da01'], 'names' => ['spironolactone']],
                'left_label' => 'Enalapril',
                'right_label' => 'Spironolactone',
                'summary' => 'This combination can increase hyperkalemia and renal-risk pressure.',
                'clinical_effect' => 'Higher potassium and renal adverse-effect risk, especially when recent renal function is impaired.',
                'recommended_action' => 'Confirm potassium and renal monitoring plans and document whether the combination is intentional.',
            ],
            [
                'code' => 'interaction_cotrimoxazole_spironolactone',
                'severity' => 'critical',
                'left' => ['codes' => ['atc:j01ee01', 'med-cotr-960tab'], 'names' => ['co-trimoxazole', 'trimethoprim', 'sulfamethoxazole']],
                'right' => ['codes' => ['atc:c03da01'], 'names' => ['spironolactone']],
                'left_label' => 'Co-trimoxazole',
                'right_label' => 'Spironolactone',
                'summary' => 'This combination can markedly increase hyperkalemia risk.',
                'clinical_effect' => 'Trimethoprim can raise potassium further when combined with potassium-sparing diuretic therapy.',
                'recommended_action' => 'Avoid routine co-use when possible, or capture a strong override with urgent potassium monitoring and documented clinical justification.',
            ],
            [
                'code' => 'interaction_cotrimoxazole_enalapril',
                'severity' => 'warning',
                'left' => ['codes' => ['atc:j01ee01', 'med-cotr-960tab'], 'names' => ['co-trimoxazole', 'trimethoprim', 'sulfamethoxazole']],
                'right' => ['codes' => ['atc:c09aa02'], 'names' => ['enalapril']],
                'left_label' => 'Co-trimoxazole',
                'right_label' => 'Enalapril',
                'summary' => 'This combination can increase potassium and renal-risk pressure.',
                'clinical_effect' => 'Trimethoprim-associated potassium rise may be amplified by ACE-inhibitor therapy, especially in renal impairment.',
                'recommended_action' => 'Review potassium and renal context, and document monitoring or a safer antibiotic plan before continuing.',
            ],
            [
                'code' => 'interaction_spironolactone_potassium_chloride',
                'severity' => 'critical',
                'left' => ['codes' => ['atc:c03da01'], 'names' => ['spironolactone']],
                'right' => ['codes' => ['atc:a12ba01'], 'names' => ['potassium chloride', 'potassium supplement']],
                'left_label' => 'Spironolactone',
                'right_label' => 'Potassium chloride',
                'summary' => 'This combination can cause severe hyperkalemia.',
                'clinical_effect' => 'Marked potassium accumulation with potentially life-threatening cardiac risk.',
                'recommended_action' => 'Avoid routine co-use unless there is a documented specialist-supported reason and very close potassium monitoring.',
            ],
            [
                'code' => 'interaction_enalapril_potassium_chloride',
                'severity' => 'warning',
                'left' => ['codes' => ['atc:c09aa02'], 'names' => ['enalapril']],
                'right' => ['codes' => ['atc:a12ba01'], 'names' => ['potassium chloride', 'potassium supplement']],
                'left_label' => 'Enalapril',
                'right_label' => 'Potassium chloride',
                'summary' => 'This combination can significantly increase serum potassium.',
                'clinical_effect' => 'Higher hyperkalemia risk, especially in renal impairment or heart failure.',
                'recommended_action' => 'Confirm why potassium supplementation is needed and document a potassium-monitoring plan before continuing.',
            ],
            [
                'code' => 'interaction_salbutamol_furosemide',
                'severity' => 'warning',
                'left' => ['codes' => ['atc:r03ac02'], 'names' => ['salbutamol', 'albuterol']],
                'right' => ['codes' => ['atc:c03ca01'], 'names' => ['furosemide']],
                'left_label' => 'Salbutamol',
                'right_label' => 'Furosemide',
                'summary' => 'Both therapies can contribute to potassium loss and rhythm instability.',
                'clinical_effect' => 'Higher hypokalemia risk with downstream rhythm concerns.',
                'recommended_action' => 'Review potassium results or clinical risk before progressing.',
            ],
            [
                'code' => 'interaction_warfarin_ibuprofen',
                'severity' => 'critical',
                'left' => ['codes' => ['atc:b01aa03'], 'names' => ['warfarin']],
                'right' => ['codes' => ['atc:m01ae01'], 'names' => ['ibuprofen']],
                'left_label' => 'Warfarin',
                'right_label' => 'Ibuprofen',
                'summary' => 'This combination substantially increases bleeding risk.',
                'clinical_effect' => 'Major bleeding-risk escalation.',
                'recommended_action' => 'Avoid the combination or document a strong override with monitoring plan.',
            ],
            [
                'code' => 'interaction_rivaroxaban_ibuprofen',
                'severity' => 'critical',
                'left' => ['codes' => ['atc:b01af01'], 'names' => ['rivaroxaban']],
                'right' => ['codes' => ['atc:m01ae01'], 'names' => ['ibuprofen']],
                'left_label' => 'Rivaroxaban',
                'right_label' => 'Ibuprofen',
                'summary' => 'Concurrent use increases clinically important bleeding risk.',
                'clinical_effect' => 'Higher gastrointestinal and systemic bleeding risk.',
                'recommended_action' => 'Prefer a safer analgesic or capture explicit override rationale and monitoring.',
            ],
            [
                'code' => 'interaction_omeprazole_clopidogrel',
                'severity' => 'warning',
                'left' => ['codes' => ['atc:a02bc01'], 'names' => ['omeprazole']],
                'right' => ['codes' => ['atc:b01ac04'], 'names' => ['clopidogrel']],
                'left_label' => 'Omeprazole',
                'right_label' => 'Clopidogrel',
                'summary' => 'Omeprazole can reduce antiplatelet activation in some patients.',
                'clinical_effect' => 'Possible reduction in clopidogrel effect.',
                'recommended_action' => 'Review whether another gastric-protection option is preferred.',
            ],
            [
                'code' => 'interaction_clarithromycin_simvastatin',
                'severity' => 'critical',
                'left' => ['codes' => ['atc:j01fa09'], 'names' => ['clarithromycin']],
                'right' => ['codes' => ['atc:c10aa01'], 'names' => ['simvastatin']],
                'left_label' => 'Clarithromycin',
                'right_label' => 'Simvastatin',
                'summary' => 'Exposure can rise sharply and increase myopathy risk.',
                'clinical_effect' => 'Higher myopathy and rhabdomyolysis risk.',
                'recommended_action' => 'Hold or replace simvastatin, or use a different antibiotic when possible.',
            ],
            [
                'code' => 'interaction_metronidazole_warfarin',
                'severity' => 'critical',
                'left' => ['codes' => ['atc:j01xd01', 'med-metr-400tab'], 'names' => ['metronidazole']],
                'right' => ['codes' => ['atc:b01aa03'], 'names' => ['warfarin']],
                'left_label' => 'Metronidazole',
                'right_label' => 'Warfarin',
                'summary' => 'This combination can significantly increase anticoagulation effect and bleeding risk.',
                'clinical_effect' => 'Higher INR exposure with clinically important bleeding-risk escalation.',
                'recommended_action' => 'Avoid the combination when possible, or document a strong override with INR monitoring and a clear bleeding-risk plan.',
            ],
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private static function matchRule(
        string $targetMedicationCode,
        string $targetMedicationName,
        string $contextMedicationCode,
        string $contextMedicationName,
    ): ?array {
        foreach (self::rules() as $rule) {
            if (
                self::matchesParticipant($targetMedicationCode, $targetMedicationName, $rule['left'])
                && self::matchesParticipant($contextMedicationCode, $contextMedicationName, $rule['right'])
            ) {
                return [
                    'code' => $rule['code'],
                    'severity' => $rule['severity'],
                    'summary' => $rule['summary'],
                    'clinical_effect' => $rule['clinical_effect'],
                    'recommended_action' => $rule['recommended_action'],
                    'target_label' => $rule['left_label'],
                    'context_label' => $rule['right_label'],
                ];
            }

            if (
                self::matchesParticipant($targetMedicationCode, $targetMedicationName, $rule['right'])
                && self::matchesParticipant($contextMedicationCode, $contextMedicationName, $rule['left'])
            ) {
                return [
                    'code' => $rule['code'],
                    'severity' => $rule['severity'],
                    'summary' => $rule['summary'],
                    'clinical_effect' => $rule['clinical_effect'],
                    'recommended_action' => $rule['recommended_action'],
                    'target_label' => $rule['right_label'],
                    'context_label' => $rule['left_label'],
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, array<int, string>> $participant
     */
    private static function matchesParticipant(
        string $medicationCode,
        string $medicationName,
        array $participant,
    ): bool {
        foreach ($participant['codes'] ?? [] as $code) {
            if ($medicationCode !== '' && self::normalize($code) === $medicationCode) {
                return true;
            }
        }

        foreach ($participant['names'] ?? [] as $name) {
            $normalizedName = self::normalize($name);
            if (
                $normalizedName !== ''
                && $medicationName !== ''
                && (
                    str_contains($medicationName, $normalizedName)
                    || str_contains($normalizedName, $medicationName)
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private static function displayMedicationLabel(
        ?string $code,
        ?string $name,
        string $fallback,
    ): string {
        $normalizedName = trim((string) ($name ?? ''));
        if ($normalizedName !== '') {
            return $normalizedName;
        }

        $normalizedCode = trim((string) ($code ?? ''));
        if ($normalizedCode !== '') {
            return $normalizedCode;
        }

        return $fallback;
    }

    private static function nullableTrim(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private static function normalize(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
