<?php

namespace App\Modules\Pharmacy\Application\Support;

final class MedicationLaboratorySafetyKnowledgeBase
{
    /**
     * @param array<int, array<string, mixed>> $laboratoryResults
     * @return list<array{
     *     rule_code:string,
     *     severity:'warning'|'critical',
     *     category:string,
     *     message:string,
     *     clinical_effect:string,
     *     recommended_action:string,
     *     source_order_id:string|null,
     *     source_test_code:string|null,
     *     source_test_name:string|null,
     *     source_result_summary:string|null,
     *     source_verified_at:string|null,
     *     source_flag:string|null
     * }>
     */
    public static function detectSignals(
        ?string $targetMedicationCode,
        ?string $targetMedicationName,
        array $laboratoryResults,
    ): array {
        $normalizedTargetCode = self::normalize($targetMedicationCode);
        $normalizedTargetName = self::normalize($targetMedicationName);

        if ($normalizedTargetCode === '' && $normalizedTargetName === '') {
            return [];
        }

        $signals = [];
        $seenRuleCodes = [];

        foreach ($laboratoryResults as $result) {
            $signal = self::matchLaboratoryResult(
                targetMedicationCode: $normalizedTargetCode,
                targetMedicationName: $normalizedTargetName,
                result: $result,
            );

            if ($signal === null) {
                continue;
            }

            $ruleCode = (string) ($signal['rule_code'] ?? '');
            if ($ruleCode !== '' && isset($seenRuleCodes[$ruleCode])) {
                continue;
            }

            $signals[] = $signal;

            if ($ruleCode !== '') {
                $seenRuleCodes[$ruleCode] = true;
            }
        }

        return $signals;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, string|null>|null
     */
    private static function matchLaboratoryResult(
        string $targetMedicationCode,
        string $targetMedicationName,
        array $result,
    ): ?array {
        $testName = self::normalize($result['test_name'] ?? null);
        $testCode = self::normalize($result['test_code'] ?? null);
        $resultSummary = trim((string) ($result['result_summary'] ?? ''));
        $resultFlag = self::extractResultFlag($resultSummary);
        $measuredResult = self::extractMeasuredResult($resultSummary);

        if (
            self::isMedication($targetMedicationCode, $targetMedicationName, ['med-iron-foltab'], ['iron + folic acid', 'iron and folic acid'])
            && self::hasSevereHemoglobinAnemia($testCode, $testName, $resultSummary, $measuredResult, $resultFlag)
        ) {
            $medicationLabel = self::displayMedicationLabel($targetMedicationName, $targetMedicationCode);

            return self::buildSignal(
                ruleCode: 'recent_severe_anemia_result_iron_folic_acid_review',
                severity: 'critical',
                message: sprintf(
                    'Recent hemoglobin review suggests %s alone may not be enough while severe anemia is present.',
                    $medicationLabel,
                ),
                clinicalEffect: 'Severe anemia may need urgent clinical reassessment beyond routine oral supplementation alone.',
                recommendedAction: 'Review the hemoglobin result urgently and document whether acute anemia management, closer follow-up, or additional treatment is needed before relying on supplementation alone.',
                result: $result,
                sourceFlag: $resultFlag,
            );
        }

        if (
            self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:c03ca01'], ['furosemide'])
            || self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:r03ac02'], ['salbutamol', 'albuterol'])
            || self::isMedication(
                $targetMedicationCode,
                $targetMedicationName,
                ['atc:p01bf01', 'med-alu-20-120tab'],
                ['artemether/lumefantrine', 'artemether lumefantrine', 'coartem'],
            )
        ) {
            $potassiumSignal = self::detectPotassiumSignal($testCode, $testName, $resultSummary, $resultFlag, $measuredResult);
            if ($potassiumSignal !== null && $potassiumSignal['direction'] === 'low') {
                $medicationLabel = self::displayMedicationLabel($targetMedicationName, $targetMedicationCode);

                if (self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:c03ca01'], ['furosemide'])) {
                    return self::buildSignal(
                        ruleCode: 'recent_low_potassium_result_furosemide_electrolyte_depletion',
                        severity: $potassiumSignal['severity'],
                        message: sprintf(
                            'Recent potassium result suggests %s may worsen electrolyte depletion if it continues unchanged.',
                            $medicationLabel,
                        ),
                        clinicalEffect: 'Loop diuresis can deepen potassium depletion and increase downstream rhythm risk.',
                        recommendedAction: 'Review potassium replacement, renal status, and whether the current diuretic plan needs adjustment before continuing.',
                        result: $result,
                        sourceFlag: $resultFlag,
                    );
                }

                if (self::isMedication(
                    $targetMedicationCode,
                    $targetMedicationName,
                    ['atc:p01bf01', 'med-alu-20-120tab'],
                    ['artemether/lumefantrine', 'artemether lumefantrine', 'coartem'],
                )) {
                    return self::buildSignal(
                        ruleCode: 'recent_low_potassium_result_artemether_lumefantrine_qt_review',
                        severity: $potassiumSignal['severity'],
                        message: sprintf(
                            'Recent potassium result suggests %s needs QT-risk review before continuing unchanged.',
                            $medicationLabel,
                        ),
                        clinicalEffect: 'Hypokalemia can increase QT-prolongation and rhythm risk during artemether-lumefantrine therapy.',
                        recommendedAction: 'Review and correct potassium if needed, then document whether ECG or closer monitoring is required before release.',
                        result: $result,
                        sourceFlag: $resultFlag,
                    );
                }

                return self::buildSignal(
                    ruleCode: 'recent_low_potassium_result',
                    severity: $potassiumSignal['severity'],
                    message: sprintf(
                        'Recent potassium result suggests hypokalemia before %s continues.',
                        $medicationLabel,
                    ),
                    clinicalEffect: 'Further potassium-lowering risk with downstream rhythm instability.',
                    recommendedAction: 'Review the potassium result and correct or document the plan before continuing therapy.',
                    result: $result,
                    sourceFlag: $resultFlag,
                );
            }
        }

        if (
            self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:c09aa02'], ['enalapril'])
            || self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:c03da01'], ['spironolactone'])
            || self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:j01ee01', 'med-cotr-960tab'], ['co-trimoxazole', 'trimethoprim', 'sulfamethoxazole'])
        ) {
            $potassiumSignal = self::detectPotassiumSignal($testCode, $testName, $resultSummary, $resultFlag, $measuredResult);
            if ($potassiumSignal !== null && $potassiumSignal['direction'] === 'high') {
                $medicationLabel = self::displayMedicationLabel($targetMedicationName, $targetMedicationCode);

                if (self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:c03da01'], ['spironolactone'])) {
                    return self::buildSignal(
                        ruleCode: 'recent_high_potassium_result_spironolactone_contraindicated_range',
                        severity: $potassiumSignal['severity'],
                        message: sprintf(
                            'Recent potassium result suggests %s should not continue unchanged while hyperkalemia is present.',
                            $medicationLabel,
                        ),
                        clinicalEffect: 'Spironolactone can worsen hyperkalemia and increase life-threatening cardiac risk.',
                        recommendedAction: 'Hold or review spironolactone urgently, confirm the potassium result, and document the release plan before continuing.',
                        result: $result,
                        sourceFlag: $resultFlag,
                    );
                }

                if (self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:j01ee01', 'med-cotr-960tab'], ['co-trimoxazole', 'trimethoprim', 'sulfamethoxazole'])) {
                    return self::buildSignal(
                        ruleCode: 'recent_high_potassium_result_cotrimoxazole_review',
                        severity: $potassiumSignal['severity'],
                        message: sprintf(
                            'Recent potassium result suggests %s should be reviewed carefully before it continues unchanged.',
                            $medicationLabel,
                        ),
                        clinicalEffect: 'Trimethoprim-containing therapy can worsen hyperkalemia and increase cardiac risk when potassium is already elevated.',
                        recommendedAction: 'Review the potassium result, assess other potassium-raising therapies, and document whether a safer antibiotic option is needed.',
                        result: $result,
                        sourceFlag: $resultFlag,
                    );
                }

                return self::buildSignal(
                    ruleCode: 'recent_high_potassium_result',
                    severity: $potassiumSignal['severity'],
                    message: sprintf(
                        'Recent potassium result suggests hyperkalemia before %s continues.',
                        $medicationLabel,
                    ),
                    clinicalEffect: 'Higher potassium-related cardiac risk if therapy continues unchanged.',
                    recommendedAction: 'Review potassium management and document the plan before continuing therapy.',
                    result: $result,
                    sourceFlag: $resultFlag,
                );
            }
        }

        if (
            self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:m01ae01', 'atc:m01ab05'], ['ibuprofen', 'diclofenac'])
            && ($renalSignal = self::detectRenalSignal($testCode, $testName, $resultSummary, $resultFlag, $measuredResult)) !== null
        ) {
            $medicationLabel = self::displayMedicationLabel($targetMedicationName, $targetMedicationCode);

            if (self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:m01ab05'], ['diclofenac'])) {
                return self::buildSignal(
                    ruleCode: $renalSignal['severity'] === 'critical'
                        ? 'recent_renal_risk_result_diclofenac_advanced_renal_disease_review'
                        : 'recent_renal_risk_result_diclofenac_review',
                    severity: $renalSignal['severity'],
                    message: sprintf(
                        $renalSignal['severity'] === 'critical'
                            ? 'Recent renal-result review suggests %s should be avoided or explicitly justified while advanced renal risk is present.'
                            : 'Recent renal-result review suggests %s needs kidney-risk review before continuing.',
                        $medicationLabel,
                    ),
                    clinicalEffect: 'Diclofenac may worsen renal function when kidney impairment is already present.',
                    recommendedAction: $renalSignal['severity'] === 'critical'
                        ? 'Review renal results urgently and document why diclofenac is still needed if no safer option is available.'
                        : 'Review renal results and consider a safer analgesic or a documented monitoring plan before continuing.',
                    result: $result,
                    sourceFlag: $resultFlag,
                );
            }

            return self::buildSignal(
                ruleCode: 'recent_renal_risk_result',
                severity: $renalSignal['severity'],
                message: sprintf(
                    'Recent renal-result review suggests kidney-risk concern before %s continues.',
                    $medicationLabel,
                ),
                clinicalEffect: 'NSAID therapy may worsen renal function when recent results already suggest impairment.',
                recommendedAction: 'Review renal results and consider a safer alternative or explicit override rationale.',
                result: $result,
                sourceFlag: $resultFlag,
            );
        }

        if (
            self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:j01ee01', 'med-cotr-960tab'], ['co-trimoxazole', 'trimethoprim', 'sulfamethoxazole'])
            && ($renalSignal = self::detectRenalSignal($testCode, $testName, $resultSummary, $resultFlag, $measuredResult)) !== null
        ) {
            $medicationLabel = self::displayMedicationLabel($targetMedicationName, $targetMedicationCode);

            return self::buildSignal(
                ruleCode: $renalSignal['severity'] === 'critical'
                    ? 'recent_renal_risk_result_cotrimoxazole_advanced_renal_risk_review'
                    : 'recent_renal_risk_result_cotrimoxazole_review',
                severity: $renalSignal['severity'],
                message: sprintf(
                    $renalSignal['severity'] === 'critical'
                        ? 'Recent renal-result review suggests %s should not continue without urgent reassessment while advanced renal risk is present.'
                        : 'Recent renal-result review suggests %s needs kidney-risk review before continuing.',
                    $medicationLabel,
                ),
                clinicalEffect: 'Reduced renal function can increase trimethoprim-sulfamethoxazole exposure and amplify renal and potassium-related adverse effects.',
                recommendedAction: $renalSignal['severity'] === 'critical'
                    ? 'Review recent renal results urgently and document whether co-trimoxazole should be held, dose-adjusted, or replaced.'
                    : 'Review renal function and document whether dose adjustment, closer monitoring, or a different antibiotic is safer.',
                result: $result,
                sourceFlag: $resultFlag,
            );
        }

        if (
            self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:a10ba02'], ['metformin'])
            && ($renalSignal = self::detectRenalSignal($testCode, $testName, $resultSummary, $resultFlag, $measuredResult)) !== null
        ) {
            $medicationLabel = self::displayMedicationLabel($targetMedicationName, $targetMedicationCode);
            $isSevereRenalImpairment = self::isSevereRenalSignal(
                $testCode,
                $testName,
                $measuredResult,
                $resultFlag,
            );

            return self::buildSignal(
                ruleCode: $isSevereRenalImpairment
                    ? 'recent_renal_risk_result_metformin_contraindicated_range'
                    : 'recent_renal_risk_result_metformin_review_range',
                severity: $renalSignal['severity'],
                message: sprintf(
                    $isSevereRenalImpairment
                        ? 'Recent renal-result review suggests %s is in a range where metformin should not continue unchanged.'
                        : 'Recent renal-result review suggests %s needs dose and benefit-risk review before continuing.',
                    $medicationLabel,
                ),
                clinicalEffect: 'Reduced renal clearance may increase metformin accumulation and downstream adverse-event risk.',
                recommendedAction: $isSevereRenalImpairment
                    ? 'Review eGFR or creatinine urgently and document whether metformin should be held or replaced.'
                    : 'Review eGFR or creatinine and document whether dose reduction or closer renal follow-up is safer.',
                result: $result,
                sourceFlag: $resultFlag,
            );
        }

        if (
            self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:j01xe01'], ['nitrofurantoin'])
            && ($nitrofurantoinBand = self::detectNitrofurantoinLowClearanceBand($testCode, $testName, $measuredResult, $resultFlag)) !== null
        ) {
            $medicationLabel = self::displayMedicationLabel($targetMedicationName, $targetMedicationCode);

            return self::buildSignal(
                ruleCode: $nitrofurantoinBand === 'contraindicated'
                    ? 'recent_renal_risk_result_nitrofurantoin_contraindicated_range'
                    : 'recent_renal_risk_result_nitrofurantoin_low_clearance_review',
                severity: $nitrofurantoinBand === 'contraindicated' ? 'critical' : 'warning',
                message: sprintf(
                    $nitrofurantoinBand === 'contraindicated'
                        ? 'Recent renal-result review suggests %s is in a range where nitrofurantoin may not be appropriate.'
                        : 'Recent renal-result review suggests %s may have reduced urinary effectiveness and higher toxicity risk with low renal clearance.',
                    $medicationLabel,
                ),
                clinicalEffect: 'Reduced renal clearance can lower urinary drug exposure while increasing systemic adverse-effect risk.',
                recommendedAction: $nitrofurantoinBand === 'contraindicated'
                    ? 'Review the recent renal result urgently and consider a different antibiotic before release.'
                    : 'Review renal function and confirm whether a different antibiotic would be safer and more effective.',
                result: $result,
                sourceFlag: $resultFlag,
            );
        }

        if (
            self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:j01ca04'], ['amoxicillin'])
            && ($renalIntervalBand = self::detectAmoxicillinRenalIntervalBand($testCode, $testName, $measuredResult)) !== null
        ) {
            $medicationLabel = self::displayMedicationLabel($targetMedicationName, $targetMedicationCode);

            return self::buildSignal(
                ruleCode: $renalIntervalBand === 'q24h'
                    ? 'recent_renal_risk_result_amoxicillin_q24_review'
                    : 'recent_renal_risk_result_amoxicillin_q12_review',
                severity: $renalIntervalBand === 'q24h' ? 'critical' : 'warning',
                message: sprintf(
                    $renalIntervalBand === 'q24h'
                        ? 'Recent eGFR suggests %s should be reviewed for a longer renal-adjusted dosing interval.'
                        : 'Recent eGFR suggests %s may need a renal-adjusted every-12-hour interval.',
                    $medicationLabel,
                ),
                clinicalEffect: 'Renal impairment can slow amoxicillin clearance and increase exposure if the dosing interval remains too short.',
                recommendedAction: $renalIntervalBand === 'q24h'
                    ? 'Review the recent eGFR and whether a 250 to 500 mg every-24-hour renal regimen is safer before release.'
                    : 'Review the recent eGFR and whether a 250 to 500 mg every-12-hour renal regimen is safer before release.',
                result: $result,
                sourceFlag: $resultFlag,
            );
        }

        if (
            self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:c09aa02'], ['enalapril'])
            && self::requiresReducedEnalaprilInitialDose($testCode, $testName, $measuredResult, $resultFlag)
        ) {
            $medicationLabel = self::displayMedicationLabel($targetMedicationName, $targetMedicationCode);

            return self::buildSignal(
                ruleCode: 'recent_renal_risk_result_enalapril_initial_dose_review',
                severity: 'warning',
                message: sprintf(
                    'Recent renal-result review suggests %s should start cautiously if therapy is being initiated.',
                    $medicationLabel,
                ),
                clinicalEffect: 'Reduced renal function may increase first-dose hypotension and renal adverse-effect risk if ACE inhibitor dosing starts too aggressively.',
                recommendedAction: 'If this is a new start, review renal function and whether a lower initial dose such as 2.5 mg once daily is safer.',
                result: $result,
                sourceFlag: $resultFlag,
            );
        }

        if (
            (
                self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:c09aa02'], ['enalapril'])
                || self::isMedication($targetMedicationCode, $targetMedicationName, ['atc:c03da01'], ['spironolactone'])
            )
            && ($renalSignal = self::detectRenalSignal($testCode, $testName, $resultSummary, $resultFlag, $measuredResult)) !== null
        ) {
            $medicationLabel = self::displayMedicationLabel($targetMedicationName, $targetMedicationCode);

            return self::buildSignal(
                ruleCode: 'recent_renal_risk_result_raas_therapy',
                severity: $renalSignal['severity'],
                message: sprintf(
                    'Recent renal-result review suggests kidney-risk concern before %s continues.',
                    $medicationLabel,
                ),
                clinicalEffect: 'Ongoing therapy may worsen renal function or compound potassium risk when renal impairment is already present.',
                recommendedAction: 'Review renal results, potassium context, and whether dose adjustment or temporary hold is safer.',
                result: $result,
                sourceFlag: $resultFlag,
            );
        }

        return null;
    }

    private static function hasSevereHemoglobinAnemia(
        string $testCode,
        string $testName,
        string $resultSummary,
        ?float $measuredResult,
        ?string $resultFlag,
    ): bool {
        if (! self::isHemoglobinTest($testCode, $testName)) {
            return false;
        }

        if ($resultFlag === 'critical') {
            return true;
        }

        if ($measuredResult === null) {
            return false;
        }

        $normalizedSummary = self::normalize($resultSummary);

        if (str_contains($normalizedSummary, 'g/dl') || str_contains($normalizedSummary, 'g dl')) {
            return $measuredResult < 7.0;
        }

        if (str_contains($normalizedSummary, 'g/l') || str_contains($normalizedSummary, 'g l')) {
            return $measuredResult < 70.0;
        }

        if ($measuredResult > 20) {
            return $measuredResult < 70.0;
        }

        return $measuredResult < 7.0;
    }

    private static function detectPotassiumSignal(
        string $testCode,
        string $testName,
        string $resultSummary,
        ?string $resultFlag,
        ?float $measuredResult,
    ): ?array {
        if (!self::isPotassiumTest($testCode, $testName)) {
            return null;
        }

        $normalizedSummary = self::normalize($resultSummary);
        $isLow = str_contains($normalizedSummary, 'hypokal')
            || str_contains($normalizedSummary, 'low potassium')
            || ($measuredResult !== null && $measuredResult < 3.5);
        $isHigh = str_contains($normalizedSummary, 'hyperkal')
            || str_contains($normalizedSummary, 'high potassium')
            || ($measuredResult !== null && $measuredResult > 5.2);

        if (!$isLow && !$isHigh) {
            return null;
        }

        if ($isLow) {
            return [
                'direction' => 'low',
                'severity' => ($resultFlag === 'critical' || ($measuredResult !== null && $measuredResult < 3.0))
                    ? 'critical'
                    : 'warning',
            ];
        }

        return [
            'direction' => 'high',
            'severity' => ($resultFlag === 'critical' || ($measuredResult !== null && $measuredResult >= 6.0))
                ? 'critical'
                : 'warning',
        ];
    }

    /**
     * @return array{severity:'warning'|'critical'}|null
     */
    private static function detectRenalSignal(
        string $testCode,
        string $testName,
        string $resultSummary,
        ?string $resultFlag,
        ?float $measuredResult,
    ): ?array {
        if (!self::isRenalTest($testCode, $testName)) {
            return null;
        }

        $normalizedSummary = self::normalize($resultSummary);

        if ($resultFlag === 'critical') {
            return ['severity' => 'critical'];
        }

        if (self::isEgfrTest($testCode, $testName)) {
            if ($measuredResult !== null && $measuredResult < 30) {
                return ['severity' => 'critical'];
            }

            if ($measuredResult !== null && $measuredResult < 45) {
                return ['severity' => 'warning'];
            }
        }

        if (self::isCreatinineTest($testCode, $testName)) {
            if ($measuredResult !== null && $measuredResult > 200) {
                return ['severity' => 'critical'];
            }

            if ($measuredResult !== null && $measuredResult > 120) {
                return ['severity' => 'warning'];
            }
        }

        if ($resultFlag === 'abnormal') {
            return ['severity' => 'warning'];
        }

        if (
            str_contains($normalizedSummary, 'aki')
            || str_contains($normalizedSummary, 'acute kidney')
            || str_contains($normalizedSummary, 'renal impairment')
            || str_contains($normalizedSummary, 'elevated creatinine')
            || str_contains($normalizedSummary, 'reduced egfr')
            || str_contains($normalizedSummary, 'low egfr')
        ) {
            return ['severity' => 'warning'];
        }

        return null;
    }

    private static function isSevereRenalSignal(
        string $testCode,
        string $testName,
        ?float $measuredResult,
        ?string $resultFlag,
    ): bool {
        if ($resultFlag === 'critical') {
            return true;
        }

        if ((str_contains($testName, 'egfr') || str_contains($testCode, 'egfr')) && $measuredResult !== null) {
            return $measuredResult < 30;
        }

        if ((str_contains($testName, 'creatinine') || str_contains($testCode, 'creatinine')) && $measuredResult !== null) {
            return $measuredResult > 200;
        }

        return false;
    }

    private static function detectAmoxicillinRenalIntervalBand(
        string $testCode,
        string $testName,
        ?float $measuredResult,
    ): ?string {
        if (!self::isEgfrTest($testCode, $testName) || $measuredResult === null) {
            return null;
        }

        if ($measuredResult < 10) {
            return 'q24h';
        }

        if ($measuredResult <= 30) {
            return 'q12h';
        }

        return null;
    }

    private static function requiresReducedEnalaprilInitialDose(
        string $testCode,
        string $testName,
        ?float $measuredResult,
        ?string $resultFlag,
    ): bool {
        if ($resultFlag === 'critical' && self::isRenalTest($testCode, $testName)) {
            return true;
        }

        if (self::isEgfrTest($testCode, $testName) && $measuredResult !== null) {
            return $measuredResult <= 30;
        }

        if (self::isCreatinineTest($testCode, $testName) && $measuredResult !== null) {
            return $measuredResult >= 265;
        }

        return false;
    }

    private static function detectNitrofurantoinLowClearanceBand(
        string $testCode,
        string $testName,
        ?float $measuredResult,
        ?string $resultFlag,
    ): ?string {
        if ($resultFlag === 'critical' && self::isRenalTest($testCode, $testName)) {
            return 'contraindicated';
        }

        if (self::isEgfrTest($testCode, $testName) && $measuredResult !== null) {
            if ($measuredResult < 30) {
                return 'contraindicated';
            }

            if ($measuredResult < 60) {
                return 'review';
            }
        }

        if (self::isCreatinineTest($testCode, $testName) && $measuredResult !== null) {
            if ($measuredResult > 200) {
                return 'contraindicated';
            }

            if ($measuredResult > 120) {
                return 'review';
            }
        }

        return null;
    }

    private static function isPotassiumTest(string $testCode, string $testName): bool
    {
        return str_contains($testName, 'potassium')
            || str_contains($testCode, 'potassium')
            || str_contains($testCode, '2823-3');
    }

    private static function isHemoglobinTest(string $testCode, string $testName): bool
    {
        return str_contains($testName, 'hemoglobin')
            || str_contains($testName, 'haemoglobin')
            || str_contains($testCode, 'hemoglobin')
            || str_contains($testCode, 'haemoglobin');
    }

    private static function isEgfrTest(string $testCode, string $testName): bool
    {
        return str_contains($testName, 'egfr')
            || str_contains($testName, 'glomerular filtration')
            || str_contains($testCode, 'egfr')
            || str_contains($testCode, '33914-3');
    }

    private static function isCreatinineTest(string $testCode, string $testName): bool
    {
        return str_contains($testName, 'creatinine')
            || str_contains($testCode, 'creatinine');
    }

    private static function isRenalTest(string $testCode, string $testName): bool
    {
        return self::isCreatinineTest($testCode, $testName)
            || self::isEgfrTest($testCode, $testName)
            || str_contains($testName, 'renal')
            || str_contains($testCode, 'renal');
    }

    /**
     * @param array<int, string> $codes
     * @param array<int, string> $names
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

    private static function extractResultFlag(string $resultSummary): ?string
    {
        if ($resultSummary === '') {
            return null;
        }

        if (preg_match('/result flag:\s*([a-z _-]+)/i', $resultSummary, $matches) !== 1) {
            return null;
        }

        $token = str_replace(' ', '_', self::normalize($matches[1] ?? null));

        if (str_contains($token, 'critical')) {
            return 'critical';
        }

        if (str_contains($token, 'abnormal')) {
            return 'abnormal';
        }

        if (str_contains($token, 'inconclusive')) {
            return 'inconclusive';
        }

        if (str_contains($token, 'normal')) {
            return 'normal';
        }

        return null;
    }

    private static function extractMeasuredResult(string $resultSummary): ?float
    {
        if ($resultSummary === '') {
            return null;
        }

        if (preg_match('/measured result:\s*([0-9]+(?:\.[0-9]+)?)/i', $resultSummary, $matches) === 1) {
            return isset($matches[1]) && is_numeric($matches[1]) ? (float) $matches[1] : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, string|null>
     */
    private static function buildSignal(
        string $ruleCode,
        string $severity,
        string $message,
        string $clinicalEffect,
        string $recommendedAction,
        array $result,
        ?string $sourceFlag,
    ): array {
        return [
            'rule_code' => $ruleCode,
            'severity' => $severity,
            'category' => 'laboratory',
            'message' => $message,
            'clinical_effect' => $clinicalEffect,
            'recommended_action' => $recommendedAction,
            'source_order_id' => self::nullableTrim($result['id'] ?? null),
            'source_test_code' => self::nullableTrim($result['test_code'] ?? null),
            'source_test_name' => self::nullableTrim($result['test_name'] ?? null),
            'source_result_summary' => self::nullableTrim($result['result_summary'] ?? null),
            'source_verified_at' => self::nullableTrim($result['verified_at'] ?? null),
            'source_flag' => $sourceFlag,
        ];
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
