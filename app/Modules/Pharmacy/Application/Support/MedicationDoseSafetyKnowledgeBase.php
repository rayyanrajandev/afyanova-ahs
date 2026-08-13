<?php

namespace App\Modules\Pharmacy\Application\Support;

final class MedicationDoseSafetyKnowledgeBase
{
    /**
     * @return list<array{
     *     code:string,
     *     severity:'warning'|'critical',
     *     category:string,
     *     message:string,
     *     suggestedAction:string|null,
     *     requiresOverride:bool
     * }>
     */
    public static function detectRules(
        ?string $targetMedicationCode,
        ?string $targetMedicationName,
        ?string $dosageInstruction,
        mixed $quantityPrescribed,
        ?string $clinicalIndication = null,
        ?int $patientAgeYears = null,
        ?int $patientAgeMonths = null,
        ?float $patientWeightKg = null,
        ?string $frequency = null,
        ?float $doseQuantity = null,
    ): array {
        $normalizedInstruction = trim((string) ($dosageInstruction ?? ''));
        $normalizedMedicationCode = self::normalize($targetMedicationCode);
        $normalizedMedicationName = self::normalize($targetMedicationName);
        $normalizedClinicalIndication = self::normalize($clinicalIndication);
        $normalizedQuantityPrescribed = self::normalizeNumeric($quantityPrescribed);

        if (
            $normalizedInstruction === ''
            && $normalizedMedicationCode === ''
            && $normalizedMedicationName === ''
        ) {
            return [];
        }

        $rules = [];

        $hasStructuredFrequency = $frequency !== null && $frequency !== '';
        if (
            $normalizedInstruction !== ''
            && ! $hasStructuredFrequency
            && ! self::hasRecognizableDoseSchedule($normalizedInstruction)
        ) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'unclear_dosing_schedule',
                severity: 'warning',
                category: 'dose',
                message: 'Dosage instruction does not include a clear schedule, duration, or PRN direction.',
                suggestedAction: 'Use a complete instruction such as "Take 1 tablet every 8 hours for 5 days" or "Take 1 tablet PRN pain".',
            );
        }

        $singleDoseQuantity = self::extractSingleDoseQuantity($normalizedInstruction);
        if (
            $singleDoseQuantity !== null
            && $normalizedQuantityPrescribed !== null
            && $normalizedQuantityPrescribed > 0
            && $normalizedQuantityPrescribed < $singleDoseQuantity
        ) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'quantity_less_than_single_dose',
                severity: 'critical',
                category: 'dose',
                message: 'Prescribed quantity is lower than the dose described in the instruction.',
                suggestedAction: 'Review the prescribed quantity and dose before continuing.',
            );
        }

        $frequencyPerDay = self::extractFrequencyPerDay($normalizedInstruction);
        $strengthInMg = self::extractStrengthInMg($targetMedicationName);
        $medicationLabel = self::displayMedicationLabel(
            medicationName: $targetMedicationName,
            medicationCode: $targetMedicationCode,
        );
        $hasInhaledInstructionContext = self::hasInhaledInstructionContext($normalizedInstruction);
        $hasNebulizedInstructionContext = self::hasNebulizedInstructionContext($normalizedInstruction);
        $hasOralInstructionContext = self::hasOralInstructionContext($normalizedInstruction);
        $hasParenteralInstructionContext = self::hasParenteralInstructionContext($normalizedInstruction);
        $hasPediatricDoseContext = $patientAgeYears !== null && $patientAgeYears < 18;
        $needsPediatricWeightReview = $hasPediatricDoseContext
            && ($patientWeightKg === null || $patientWeightKg <= 0)
            && $singleDoseQuantity !== null
            && $frequencyPerDay !== null
            && $strengthInMg !== null
            && self::isMedication(
                $normalizedMedicationCode,
                $normalizedMedicationName,
                ['atc:n02be01', 'atc:m01ae01', 'atc:j01ca04', 'atc:j01dd04'],
                ['paracetamol', 'acetaminophen', 'ibuprofen', 'amoxicillin', 'ceftriaxone'],
            );

        if ($needsPediatricWeightReview) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'pediatric_weight_context_missing',
                severity: 'warning',
                category: 'dose',
                message: 'A recent pediatric weight is not available for dose review.',
                suggestedAction: 'Confirm the current weight before finalizing pediatric dosing for this medicine.',
            );
        }

        if (
            self::isMedication(
                $normalizedMedicationCode,
                $normalizedMedicationName,
                ['atc:n02be01'],
                ['paracetamol', 'acetaminophen'],
            )
            && $singleDoseQuantity !== null
            && $frequencyPerDay !== null
            && $strengthInMg !== null
        ) {
            $dailyDoseMg = $singleDoseQuantity * $frequencyPerDay * $strengthInMg;

            if ($dailyDoseMg > 4000) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'paracetamol_daily_dose_above_max',
                    severity: 'critical',
                    category: 'dose',
                    message: sprintf(
                        'Estimated scheduled %s dose exceeds 4 g per day.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Reduce the per-dose amount or frequency, or document a clear clinical reason before continuing.',
                );
            }

            if ($hasPediatricDoseContext && $patientWeightKg !== null && $patientWeightKg > 0) {
                $dailyDoseMgPerKg = $dailyDoseMg / $patientWeightKg;

                if ($dailyDoseMgPerKg > 75) {
                    $rules[] = MedicationSafetyRuleCatalog::makeRule(
                        code: 'paracetamol_weight_based_daily_dose_above_max',
                        severity: 'critical',
                        category: 'dose',
                        message: sprintf(
                            'Estimated scheduled %s dose is %.1f mg/kg/day, above the usual pediatric maximum.',
                            $medicationLabel,
                            $dailyDoseMgPerKg,
                        ),
                        suggestedAction: 'Reduce the pediatric dose or frequency after confirming the current weight.',
                    );
                } elseif ($dailyDoseMgPerKg > 60) {
                    $rules[] = MedicationSafetyRuleCatalog::makeRule(
                        code: 'paracetamol_weight_based_daily_dose_high',
                        severity: 'warning',
                        category: 'dose',
                        message: sprintf(
                            'Estimated scheduled %s dose is %.1f mg/kg/day, which is high for pediatric dosing.',
                            $medicationLabel,
                            $dailyDoseMgPerKg,
                        ),
                        suggestedAction: 'Confirm the current weight and whether a lower pediatric dose would still meet the clinical goal.',
                    );
                }
            }
        }

        if (
            self::isMedication(
                $normalizedMedicationCode,
                $normalizedMedicationName,
                ['atc:m01ae01'],
                ['ibuprofen'],
            )
            && $singleDoseQuantity !== null
            && $frequencyPerDay !== null
            && $strengthInMg !== null
        ) {
            $dailyDoseMg = $singleDoseQuantity * $frequencyPerDay * $strengthInMg;

            if ($dailyDoseMg > 3200) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'ibuprofen_daily_dose_above_max',
                    severity: 'critical',
                    category: 'dose',
                    message: sprintf(
                        'Estimated scheduled %s dose exceeds the usual maximum daily dose.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Reduce the dose, widen the interval, or choose a safer analgesic before continuing.',
                );
            } elseif ($dailyDoseMg > 2400) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'ibuprofen_daily_dose_high',
                    severity: 'warning',
                    category: 'dose',
                    message: sprintf(
                        'Estimated scheduled %s dose is in a high daily-dose range.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Confirm the indication, renal-risk context, and whether a lower dose or safer option is appropriate.',
                );
            }

            if ($patientAgeMonths !== null && $patientAgeMonths < 6) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'ibuprofen_age_below_supported_threshold',
                    severity: 'critical',
                    category: 'dose',
                    message: sprintf(
                        '%s needs age-specific review because the patient is younger than 6 months.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Avoid routine ibuprofen use at this age unless the clinical team documents a clear specialist-supported plan.',
                );
            }

            if ($hasPediatricDoseContext && $patientWeightKg !== null && $patientWeightKg > 0) {
                $dailyDoseMgPerKg = $dailyDoseMg / $patientWeightKg;

                if ($dailyDoseMgPerKg > 40) {
                    $rules[] = MedicationSafetyRuleCatalog::makeRule(
                        code: 'ibuprofen_weight_based_daily_dose_above_max',
                        severity: 'critical',
                        category: 'dose',
                        message: sprintf(
                            'Estimated scheduled %s dose is %.1f mg/kg/day, above the usual pediatric maximum.',
                            $medicationLabel,
                            $dailyDoseMgPerKg,
                        ),
                        suggestedAction: 'Reduce the pediatric dose or frequency after confirming the current weight.',
                    );
                } elseif ($dailyDoseMgPerKg > 30) {
                    $rules[] = MedicationSafetyRuleCatalog::makeRule(
                        code: 'ibuprofen_weight_based_daily_dose_high',
                        severity: 'warning',
                        category: 'dose',
                        message: sprintf(
                            'Estimated scheduled %s dose is %.1f mg/kg/day, which is high for pediatric dosing.',
                            $medicationLabel,
                            $dailyDoseMgPerKg,
                        ),
                        suggestedAction: 'Confirm the current weight and whether a lower pediatric dose would still be effective.',
                    );
                }
            }
        }

        if (
            self::isMedication(
                $normalizedMedicationCode,
                $normalizedMedicationName,
                ['atc:j01ca04'],
                ['amoxicillin'],
            )
            && $singleDoseQuantity !== null
            && $frequencyPerDay !== null
            && $strengthInMg !== null
            && $hasPediatricDoseContext
        ) {
            $dailyDoseMg = $singleDoseQuantity * $frequencyPerDay * $strengthInMg;

            if ($patientAgeMonths !== null && $patientAgeMonths <= 3) {
                if ($frequencyPerDay > 2.0) {
                    $rules[] = MedicationSafetyRuleCatalog::makeRule(
                        code: 'amoxicillin_neonate_frequency_too_high',
                        severity: 'critical',
                        category: 'dose',
                        message: sprintf(
                            '%s frequency is higher than the usual every-12-hour neonatal schedule.',
                            $medicationLabel,
                        ),
                        suggestedAction: 'Review the neonatal interval and reduce frequency unless a specialist-supported reason is documented.',
                    );
                }

                if ($patientWeightKg !== null && $patientWeightKg > 0) {
                    $dailyDoseMgPerKg = $dailyDoseMg / $patientWeightKg;

                    if ($dailyDoseMgPerKg > 30) {
                        $rules[] = MedicationSafetyRuleCatalog::makeRule(
                            code: 'amoxicillin_neonate_daily_dose_above_max',
                            severity: 'critical',
                            category: 'dose',
                            message: sprintf(
                                'Estimated scheduled %s dose is %.1f mg/kg/day, above the usual neonatal upper limit.',
                                $medicationLabel,
                                $dailyDoseMgPerKg,
                            ),
                            suggestedAction: 'Reduce the neonatal dose after confirming the current weight and intended indication.',
                        );
                    } elseif ($dailyDoseMgPerKg > 25) {
                        $rules[] = MedicationSafetyRuleCatalog::makeRule(
                            code: 'amoxicillin_neonate_daily_dose_high',
                            severity: 'warning',
                            category: 'dose',
                            message: sprintf(
                                'Estimated scheduled %s dose is %.1f mg/kg/day, near the neonatal upper limit.',
                                $medicationLabel,
                                $dailyDoseMgPerKg,
                            ),
                            suggestedAction: 'Confirm the current weight and whether the neonatal dose and interval are both correct.',
                        );
                    }
                }
            } elseif ($patientWeightKg !== null && $patientWeightKg > 0) {
                $dailyDoseMgPerKg = $dailyDoseMg / $patientWeightKg;

                if ($dailyDoseMgPerKg > 50) {
                    $rules[] = MedicationSafetyRuleCatalog::makeRule(
                        code: 'amoxicillin_weight_based_daily_dose_above_max',
                        severity: 'critical',
                        category: 'dose',
                        message: sprintf(
                            'Estimated scheduled %s dose is %.1f mg/kg/day, above the usual pediatric range.',
                            $medicationLabel,
                            $dailyDoseMgPerKg,
                        ),
                        suggestedAction: 'Reduce the pediatric dose or document why a higher total daily dose is necessary.',
                    );
                } elseif ($dailyDoseMgPerKg > 45) {
                    $rules[] = MedicationSafetyRuleCatalog::makeRule(
                        code: 'amoxicillin_weight_based_daily_dose_high',
                        severity: 'warning',
                        category: 'dose',
                        message: sprintf(
                            'Estimated scheduled %s dose is %.1f mg/kg/day, at the upper end of usual pediatric dosing.',
                            $medicationLabel,
                            $dailyDoseMgPerKg,
                        ),
                        suggestedAction: 'Confirm the infection severity, current weight, and whether the higher pediatric dose is intended.',
                    );
                }
            }
        }

        $isSalbutamolMedication = self::isMedication(
            $normalizedMedicationCode,
            $normalizedMedicationName,
            ['atc:r03ac02'],
            ['salbutamol', 'albuterol'],
        );

        if ($isSalbutamolMedication) {
            $isLikelyInhalerProduct = self::isLikelyInhalerProduct($normalizedMedicationName);

            if ($isLikelyInhalerProduct && $hasOralInstructionContext) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'salbutamol_inhaler_route_mismatch',
                    severity: 'critical',
                    category: 'dose',
                    message: sprintf(
                        '%s looks like an inhaler product, but the instruction reads like an oral medicine order.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Review the salbutamol form and rewrite the instruction using inhalation wording such as puffs or inhalations before continuing.',
                );
            } elseif (! $hasInhaledInstructionContext && ! $hasNebulizedInstructionContext) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'salbutamol_route_form_review_required',
                    severity: 'warning',
                    category: 'dose',
                    message: sprintf(
                        '%s needs route and form review because inhaled dose checks do not apply to every salbutamol formulation.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Confirm whether the order is inhaled, nebulized, or oral and document the instruction in a form-specific way.',
                );
            }
        }

        if (
            $isSalbutamolMedication
            && $singleDoseQuantity !== null
            && $frequencyPerDay !== null
            && $hasInhaledInstructionContext
        ) {
            $dailyPuffs = $singleDoseQuantity * $frequencyPerDay;

            if ($dailyPuffs > 16) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'salbutamol_inhaled_frequency_above_max',
                    severity: 'critical',
                    category: 'dose',
                    message: sprintf(
                        'Estimated scheduled %s use exceeds a high rescue-inhaler frequency threshold.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Review the patient urgently for uncontrolled bronchospasm and document the escalation plan before continuing.',
                );
            } elseif ($dailyPuffs > 12) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'salbutamol_inhaled_frequency_high',
                    severity: 'warning',
                    category: 'dose',
                    message: sprintf(
                        'Estimated scheduled %s use is higher than usual rescue-inhaler frequency.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Review control status and confirm whether escalation or reassessment is needed.',
                );
            }
        }

        if (
            self::isMedication(
                $normalizedMedicationCode,
                $normalizedMedicationName,
                ['atc:j01dd04'],
                ['ceftriaxone'],
            )
            && $singleDoseQuantity !== null
            && $frequencyPerDay !== null
            && $strengthInMg !== null
        ) {
            if ($hasOralInstructionContext) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'ceftriaxone_route_form_mismatch',
                    severity: 'critical',
                    category: 'dose',
                    message: sprintf(
                        '%s is a parenteral medicine, but the instruction reads like an oral tablet or capsule order.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Rewrite the order using IV or IM administration wording before continuing.',
                );
            } elseif (! $hasParenteralInstructionContext) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'ceftriaxone_route_missing',
                    severity: 'warning',
                    category: 'dose',
                    message: sprintf(
                        '%s needs explicit IV or IM administration wording for safe release.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Document whether the dose is intravenous or intramuscular before continuing.',
                );
            }

            $dailyDoseMg = $singleDoseQuantity * $frequencyPerDay * $strengthInMg;

            if ($patientAgeMonths !== null && $patientAgeMonths < 1) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'ceftriaxone_neonate_review_required',
                    severity: 'warning',
                    category: 'dose',
                    message: sprintf(
                        '%s needs explicit neonatal review before release.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Confirm prematurity, hyperbilirubinemia status, and whether calcium-containing IV therapy is present before continuing ceftriaxone in a neonate.',
                );
            }

            if ($dailyDoseMg > 4000) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'ceftriaxone_daily_dose_above_max',
                    severity: 'critical',
                    category: 'dose',
                    message: sprintf(
                        'Estimated scheduled %s dose exceeds 4 g per day.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Review the indication, patient-specific context, and dosing interval before continuing.',
                );
            }

            if ($hasPediatricDoseContext && $patientWeightKg !== null && $patientWeightKg > 0) {
                $dailyDoseMgPerKg = $dailyDoseMg / $patientWeightKg;

                if ($dailyDoseMgPerKg > 100) {
                    $rules[] = MedicationSafetyRuleCatalog::makeRule(
                        code: 'ceftriaxone_weight_based_daily_dose_above_max',
                        severity: 'critical',
                        category: 'dose',
                        message: sprintf(
                            'Estimated scheduled %s dose is %.1f mg/kg/day, above the usual pediatric maximum.',
                            $medicationLabel,
                            $dailyDoseMgPerKg,
                        ),
                        suggestedAction: 'Review the pediatric dose, weight, and indication before continuing.',
                    );
                } elseif ($dailyDoseMgPerKg > 80) {
                    $rules[] = MedicationSafetyRuleCatalog::makeRule(
                        code: 'ceftriaxone_weight_based_daily_dose_high',
                        severity: 'warning',
                        category: 'dose',
                        message: sprintf(
                            'Estimated scheduled %s dose is %.1f mg/kg/day, which is high for pediatric dosing.',
                            $medicationLabel,
                            $dailyDoseMgPerKg,
                        ),
                        suggestedAction: 'Confirm the current weight and whether the dose matches the intended severity of infection.',
                    );
                }
            }
        }

        if (
            self::isMedication(
                $normalizedMedicationCode,
                $normalizedMedicationName,
                ['med-metr-400tab'],
                ['metronidazole'],
            )
        ) {
            if ($hasParenteralInstructionContext) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'metronidazole_route_form_mismatch',
                    severity: 'critical',
                    category: 'dose',
                    message: sprintf(
                        '%s is an oral tablet, but the instruction reads like an injectable or infusion order.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Rewrite the order using oral tablet wording before continuing metronidazole release.',
                );
            } elseif (! $hasOralInstructionContext) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'metronidazole_route_review_required',
                    severity: 'warning',
                    category: 'dose',
                    message: sprintf(
                        '%s needs clear oral administration wording for safe release.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Document the oral tablet regimen clearly before continuing.',
                );
            }
        }

        if (
            self::isMedication(
                $normalizedMedicationCode,
                $normalizedMedicationName,
                ['med-oxyt-10inj'],
                ['oxytocin'],
            )
        ) {
            if ($hasOralInstructionContext) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'oxytocin_route_form_mismatch',
                    severity: 'critical',
                    category: 'dose',
                    message: sprintf(
                        '%s is an injection medicine, but the instruction reads like an oral tablet or capsule order.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Rewrite the order using IV, IM, infusion, or other injection administration wording before continuing.',
                );
            } elseif (! $hasParenteralInstructionContext) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'oxytocin_route_missing',
                    severity: 'warning',
                    category: 'dose',
                    message: sprintf(
                        '%s needs explicit IV, IM, or infusion wording for safe release.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Document the intended oxytocin administration route clearly before continuing.',
                );
            }
        }

        if (
            self::isMedication(
                $normalizedMedicationCode,
                $normalizedMedicationName,
                ['med-alu-20-120tab'],
                ['artemether/lumefantrine', 'artemether lumefantrine', 'coartem'],
            )
        ) {
            if ($hasParenteralInstructionContext) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'artemether_lumefantrine_route_form_mismatch',
                    severity: 'critical',
                    category: 'dose',
                    message: sprintf(
                        '%s is an oral antimalarial, but the instruction reads like an injectable or infusion order.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Rewrite the order using oral tablet wording before continuing this antimalarial regimen.',
                );
            } elseif (! $hasOralInstructionContext) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'artemether_lumefantrine_route_review_required',
                    severity: 'warning',
                    category: 'dose',
                    message: sprintf(
                        '%s needs clear oral administration wording for safe release.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Document the oral tablet regimen clearly before continuing.',
                );
            }

            if (! self::hasFoodAdministrationContext($normalizedInstruction)) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'artemether_lumefantrine_food_administration_review',
                    severity: 'warning',
                    category: 'dose',
                    message: sprintf(
                        '%s should include food or milk administration guidance to support absorption.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Add administration wording such as taking the dose with food, milk, or another tolerated fatty meal when clinically feasible.',
                );
            }

            if (self::isSevereMalariaIndication($normalizedClinicalIndication)) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'artemether_lumefantrine_severe_malaria_review',
                    severity: 'critical',
                    category: 'indication',
                    message: sprintf(
                        '%s is not the right first-line path for severe or complicated malaria.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Review the severe-malaria pathway and document whether parenteral treatment is required before using oral follow-on therapy.',
                );
            } elseif (self::isMalariaPreventionIndication($normalizedClinicalIndication)) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'artemether_lumefantrine_prophylaxis_review',
                    severity: 'warning',
                    category: 'indication',
                    message: sprintf(
                        '%s is being reviewed for prophylaxis or prevention wording that does not fit routine treatment use.',
                        $medicationLabel,
                    ),
                    suggestedAction: 'Confirm that this is intended for treatment of acute uncomplicated malaria rather than prevention.',
                );
            }
        }

        return $rules;
    }

    private static function hasRecognizableDoseSchedule(string $dosageInstruction): bool
    {
        $normalizedInstruction = self::normalize($dosageInstruction);
        if ($normalizedInstruction === '') {
            return false;
        }

        $patterns = [
            '/\bevery\s+\d+\s*(hour|hours|hr|hrs|day|days|week|weeks)\b/u',
            '/\b(once|twice)\s+(daily|a day)\b/u',
            '/\b(one|two|three|four)\s+times?\s+(daily|a day|per day)\b/u',
            '/\b(bid|tid|qid|q\d+h|qhs|od|bd|tds|prn|stat)\b/u',
            '/\b(as needed|at night|bedtime|weekly|monthly|for\s+\d+\s*(day|days|week|weeks))\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedInstruction) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function extractSingleDoseQuantity(string $dosageInstruction): ?float
    {
        $normalizedInstruction = self::normalize($dosageInstruction);
        if ($normalizedInstruction === '') {
            return null;
        }

        if (
            preg_match(
                '/(?:take|give|apply|use|inhale|insert|inject)?\s*(\d+(?:\.\d+)?)\s*(tablets?|tabs?|capsules?|caps?|ml|drops?|puffs?|patch(?:es)?|suppositor(?:y|ies)|sachets?|sprays?|vials?)\b/u',
                $normalizedInstruction,
                $matches,
            ) === 1
        ) {
            return isset($matches[1]) && is_numeric($matches[1]) ? (float) $matches[1] : null;
        }

        return null;
    }

    private static function extractFrequencyPerDay(string $dosageInstruction): ?float
    {
        $normalizedInstruction = self::normalize($dosageInstruction);
        if ($normalizedInstruction === '') {
            return null;
        }

        if (preg_match('/\bevery\s+(\d+(?:\.\d+)?)\s*(hour|hours|hr|hrs)\b/u', $normalizedInstruction, $matches) === 1) {
            $hours = isset($matches[1]) && is_numeric($matches[1]) ? (float) $matches[1] : null;
            if ($hours !== null && $hours > 0) {
                return 24 / $hours;
            }
        }

        if (preg_match('/\bevery\s+(\d+(?:\.\d+)?)\s*(day|days)\b/u', $normalizedInstruction, $matches) === 1) {
            $days = isset($matches[1]) && is_numeric($matches[1]) ? (float) $matches[1] : null;
            if ($days !== null && $days > 0) {
                return 1 / $days;
            }
        }

        if (
            preg_match('/\b(once|twice)\s+(daily|a day)\b/u', $normalizedInstruction, $matches) === 1
        ) {
            return ($matches[1] ?? '') === 'twice' ? 2.0 : 1.0;
        }

        if (
            preg_match('/\b(one|two|three|four)\s+times?\s+(daily|a day|per day)\b/u', $normalizedInstruction, $matches) === 1
        ) {
            return match ($matches[1] ?? '') {
                'one' => 1.0,
                'two' => 2.0,
                'three' => 3.0,
                'four' => 4.0,
                default => null,
            };
        }

        if (preg_match('/\bq(\d+)h\b/u', $normalizedInstruction, $matches) === 1) {
            $hours = isset($matches[1]) && is_numeric($matches[1]) ? (float) $matches[1] : null;
            if ($hours !== null && $hours > 0) {
                return 24 / $hours;
            }
        }

        foreach ([
            'qid' => 4.0,
            'tds' => 3.0,
            'tid' => 3.0,
            'bd' => 2.0,
            'bid' => 2.0,
            'od' => 1.0,
            'qhs' => 1.0,
        ] as $token => $frequencyPerDay) {
            if (preg_match('/\b'.preg_quote($token, '/').'\b/u', $normalizedInstruction) === 1) {
                return $frequencyPerDay;
            }
        }

        if (
            preg_match('/\b(at night|bedtime)\b/u', $normalizedInstruction) === 1
        ) {
            return 1.0;
        }

        return null;
    }

    private static function hasInhaledInstructionContext(string $normalizedInstruction): bool
    {
        $normalizedInstruction = self::normalize($normalizedInstruction);
        if ($normalizedInstruction === '') {
            return false;
        }

        return preg_match('/\b(puff|puffs|inhale|inhalation|inhalations)\b/u', $normalizedInstruction) === 1;
    }

    private static function hasNebulizedInstructionContext(string $normalizedInstruction): bool
    {
        $normalizedInstruction = self::normalize($normalizedInstruction);
        if ($normalizedInstruction === '') {
            return false;
        }

        return preg_match('/\b(neb|nebule|nebules|nebulize|nebulise|nebulizer|nebuliser|respule|respules)\b/u', $normalizedInstruction) === 1;
    }

    private static function hasOralInstructionContext(string $normalizedInstruction): bool
    {
        $normalizedInstruction = self::normalize($normalizedInstruction);
        if ($normalizedInstruction === '') {
            return false;
        }

        return preg_match('/\b(take|tablet|tablets|tab|tabs|capsule|capsules|cap|caps|syrup|suspension|oral|by mouth|po|ml)\b/u', $normalizedInstruction) === 1;
    }

    private static function hasParenteralInstructionContext(string $normalizedInstruction): bool
    {
        $normalizedInstruction = self::normalize($normalizedInstruction);
        if ($normalizedInstruction === '') {
            return false;
        }

        return preg_match('/\b(give|inject|injection|iv|i\.v\.|im|i\.m\.|intravenous|intramuscular|infuse|infusion|vial|ampoule|ampule)\b/u', $normalizedInstruction) === 1;
    }

    private static function hasFoodAdministrationContext(string $normalizedInstruction): bool
    {
        $normalizedInstruction = self::normalize($normalizedInstruction);
        if ($normalizedInstruction === '') {
            return false;
        }

        return preg_match('/\b(with food|after food|after meals|with meals|with milk|after eating|meal|meals|milk|fatty meal|fat-containing)\b/u', $normalizedInstruction) === 1;
    }

    private static function isLikelyInhalerProduct(string $normalizedMedicationName): bool
    {
        if ($normalizedMedicationName === '') {
            return false;
        }

        return preg_match('/\b(inhaler|aerosol|mdi|hfa)\b/u', $normalizedMedicationName) === 1;
    }

    private static function isSevereMalariaIndication(string $normalizedClinicalIndication): bool
    {
        if ($normalizedClinicalIndication === '') {
            return false;
        }

        return preg_match('/\b(severe malaria|complicated malaria|cerebral malaria|malaria with shock|severe falciparum)\b/u', $normalizedClinicalIndication) === 1;
    }

    private static function isMalariaPreventionIndication(string $normalizedClinicalIndication): bool
    {
        if ($normalizedClinicalIndication === '') {
            return false;
        }

        return preg_match('/\b(prophylaxis|prophylactic|prevention|preventive)\b/u', $normalizedClinicalIndication) === 1;
    }

    private static function extractStrengthInMg(?string $medicationName): ?float
    {
        $normalizedMedicationName = trim((string) ($medicationName ?? ''));
        if ($normalizedMedicationName === '') {
            return null;
        }

        if (
            preg_match(
                '/(\d+(?:\.\d+)?)\s*(mg|g|mcg|μg|ug)\b/u',
                $normalizedMedicationName,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        $amount = isset($matches[1]) && is_numeric($matches[1]) ? (float) $matches[1] : null;
        $unit = self::normalize($matches[2] ?? null);

        if ($amount === null || $unit === '') {
            return null;
        }

        return match ($unit) {
            'g' => $amount * 1000,
            'mcg', 'μg', 'ug' => $amount / 1000,
            default => $amount,
        };
    }

    /**
     * @param list<string> $codes
     * @param list<string> $names
     */
    private static function isMedication(
        string $medicationCode,
        string $medicationName,
        array $codes,
        array $names,
    ): bool {
        foreach ($codes as $code) {
            if ($medicationCode !== '' && self::normalize($code) === $medicationCode) {
                return true;
            }
        }

        foreach ($names as $name) {
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

    private static function displayMedicationLabel(?string $medicationName, ?string $medicationCode): string
    {
        $normalizedName = trim((string) ($medicationName ?? ''));
        if ($normalizedName !== '') {
            return $normalizedName;
        }

        $normalizedCode = trim((string) ($medicationCode ?? ''));
        if ($normalizedCode !== '') {
            return $normalizedCode;
        }

        return 'this medicine';
    }

    private static function normalizeNumeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function normalize(mixed $value): string
    {
        return mb_strtolower(trim((string) ($value ?? '')));
    }
}
