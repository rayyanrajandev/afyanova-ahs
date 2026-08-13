<?php

namespace App\Modules\Pharmacy\Application\Support;

final class MedicationSafetyRuleCatalog
{
    public static function catalogVersion(): string
    {
        return 'pharmacy-medication-safety.v2';
    }

    /**
     * @return list<array{code:string,label:string,description:string}>
     */
    public static function overrideOptions(): array
    {
        return [
            [
                'code' => 'benefit_outweighs_risk',
                'label' => 'Benefit outweighs risk',
                'description' => 'Proceed after review because the expected benefit is greater than the identified medication risk.',
            ],
            [
                'code' => 'urgent_clinical_need',
                'label' => 'Urgent clinical need',
                'description' => 'Therapy cannot be delayed safely while the clinical team completes additional review.',
            ],
            [
                'code' => 'reviewed_known_tolerance',
                'label' => 'Reviewed known tolerance',
                'description' => 'History was reviewed and the documented reaction or alert does not apply to this medicine as currently ordered.',
            ],
            [
                'code' => 'intentional_therapy_overlap',
                'label' => 'Intentional therapy overlap',
                'description' => 'The apparent duplicate or overlap is clinically intentional during transition, taper, or combination therapy.',
            ],
            [
                'code' => 'reconciliation_pending',
                'label' => 'Reconciliation pending',
                'description' => 'Proceed now while broader medication reconciliation follow-up is still being completed.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function validOverrideCodes(): array
    {
        return array_values(array_map(
            static fn (array $option): string => $option['code'],
            self::overrideOptions(),
        ));
    }

    public static function isValidOverrideCode(?string $code): bool
    {
        $normalizedCode = trim((string) ($code ?? ''));

        return $normalizedCode !== ''
            && in_array($normalizedCode, self::validOverrideCodes(), true);
    }

    /**
     * @return array{code:string,label:string,description:string}|null
     */
    public static function findOverrideOption(?string $code): ?array
    {
        $normalizedCode = trim((string) ($code ?? ''));
        if ($normalizedCode === '') {
            return null;
        }

        foreach (self::overrideOptions() as $option) {
            if ($option['code'] === $normalizedCode) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     code:string,
     *     severity:'warning'|'critical',
     *     category:string,
     *     categoryLabel:string,
     *     message:string,
     *     suggestedAction:string|null,
     *     requiresOverride:bool,
     *     source:array{
     *         type:string,
     *         label:string,
     *         referenceId:string|null,
     *         referenceLabel:string|null,
     *         observedAt:string|null,
     *         flag:string|null
     *     }|null
     * }
     */
    public static function makeRule(
        string $code,
        string $severity,
        string $category,
        string $message,
        ?string $suggestedAction = null,
        ?array $source = null,
    ): array {
        $normalizedSeverity = strtolower(trim($severity));
        if (! in_array($normalizedSeverity, ['warning', 'critical'], true)) {
            $normalizedSeverity = 'warning';
        }

        $normalizedCategory = trim($category) !== '' ? trim($category) : 'general';

        return [
            'code' => trim($code),
            'severity' => $normalizedSeverity,
            'category' => $normalizedCategory,
            'categoryLabel' => self::categoryLabel($normalizedCategory),
            'message' => trim($message),
            'suggestedAction' => $suggestedAction !== null && trim($suggestedAction) !== ''
                ? trim($suggestedAction)
                : null,
            'requiresOverride' => $normalizedSeverity === 'critical',
            'source' => self::normalizeSource($normalizedCategory, $source),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array{
     *     severity:'none'|'warning'|'critical',
     *     blockers:list<string>,
     *     warnings:list<string>,
     *     suggestedActions:list<string>,
     *     ruleCodes:list<string>
     * }
     */
    public static function summarizeRules(array $rules): array
    {
        $blockers = [];
        $warnings = [];
        $suggestedActions = [];
        $ruleCodes = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $message = trim((string) ($rule['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $severity = strtolower(trim((string) ($rule['severity'] ?? 'warning')));
            if ($severity === 'critical') {
                $blockers[] = $message;
            } else {
                $warnings[] = $message;
            }

            $ruleCode = trim((string) ($rule['code'] ?? ''));
            if ($ruleCode !== '') {
                $ruleCodes[] = $ruleCode;
            }

            $suggestedAction = trim((string) ($rule['suggestedAction'] ?? ''));
            if ($suggestedAction !== '') {
                $suggestedActions[] = $suggestedAction;
            }
        }

        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_unique($warnings));
        $suggestedActions = array_values(array_unique($suggestedActions));
        $ruleCodes = array_values(array_unique($ruleCodes));

        return [
            'severity' => $blockers !== []
                ? 'critical'
                : ($warnings !== [] ? 'warning' : 'none'),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'suggestedActions' => $suggestedActions,
            'ruleCodes' => $ruleCodes,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return list<array{
     *     key:string,
     *     label:string,
     *     severity:'warning'|'critical',
     *     count:int,
     *     ruleCodes:list<string>,
     *     sourceLabels:list<string>
     * }>
     */
    public static function groupRules(array $rules): array
    {
        $groups = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $category = trim((string) ($rule['category'] ?? 'general'));
            if ($category === '') {
                $category = 'general';
            }

            if (! isset($groups[$category])) {
                $groups[$category] = [
                    'key' => $category,
                    'label' => self::categoryLabel($category),
                    'severity' => 'warning',
                    'count' => 0,
                    'ruleCodes' => [],
                    'sourceLabels' => [],
                ];
            }

            $groups[$category]['count']++;

            $severity = strtolower(trim((string) ($rule['severity'] ?? 'warning')));
            if ($severity === 'critical') {
                $groups[$category]['severity'] = 'critical';
            }

            $ruleCode = trim((string) ($rule['code'] ?? ''));
            if ($ruleCode !== '') {
                $groups[$category]['ruleCodes'][] = $ruleCode;
            }

            $source = $rule['source'] ?? null;
            if (is_array($source)) {
                $sourceLabel = trim((string) ($source['label'] ?? ''));
                if ($sourceLabel !== '') {
                    $groups[$category]['sourceLabels'][] = $sourceLabel;
                }
            }
        }

        return array_values(array_map(
            static function (array $group): array {
                $group['ruleCodes'] = array_values(array_unique($group['ruleCodes']));
                $group['sourceLabels'] = array_values(array_unique($group['sourceLabels']));

                return $group;
            },
            $groups,
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array{
     *     required:bool,
     *     applied:bool,
     *     code:string|null,
     *     label:string|null,
     *     reason:string|null,
     *     blockerCount:int,
     *     warningCount:int,
     *     overriddenRuleCodes:list<string>,
     *     overriddenCategories:list<string>
     * }
     */
    public static function buildOverrideSummary(
        array $rules,
        ?string $overrideCode,
        ?string $overrideReason,
    ): array {
        $normalizedOverrideCode = trim((string) ($overrideCode ?? ''));
        $normalizedOverrideReason = trim((string) ($overrideReason ?? ''));
        $criticalRules = array_values(array_filter(
            $rules,
            static fn (mixed $rule): bool => is_array($rule)
                && strtolower(trim((string) ($rule['severity'] ?? 'warning'))) === 'critical',
        ));
        $warningRules = array_values(array_filter(
            $rules,
            static fn (mixed $rule): bool => is_array($rule)
                && strtolower(trim((string) ($rule['severity'] ?? 'warning'))) !== 'critical',
        ));

        return [
            'required' => $criticalRules !== [],
            'applied' => $normalizedOverrideCode !== '',
            'code' => $normalizedOverrideCode !== '' ? $normalizedOverrideCode : null,
            'label' => self::findOverrideOption($normalizedOverrideCode)['label'] ?? null,
            'reason' => $normalizedOverrideReason !== '' ? $normalizedOverrideReason : null,
            'blockerCount' => count($criticalRules),
            'warningCount' => count($warningRules),
            'overriddenRuleCodes' => array_values(array_unique(array_filter(array_map(
                static fn (array $rule): string => trim((string) ($rule['code'] ?? '')),
                $criticalRules,
            )))),
            'overriddenCategories' => array_values(array_unique(array_filter(array_map(
                static fn (array $rule): string => trim((string) ($rule['category'] ?? '')),
                $criticalRules,
            )))),
        ];
    }

    private static function categoryLabel(string $category): string
    {
        return match ($category) {
            'allergy' => 'Allergy and Intolerance',
            'dose' => 'Dose and Schedule',
            'duplicate_therapy' => 'Duplicate Therapy',
            'history' => 'Recent Medication History',
            'indication' => 'Clinical Indication',
            'interaction' => 'Drug Interaction',
            'inventory' => 'Dispense Inventory',
            'laboratory' => 'Laboratory Result',
            'policy' => 'Approved Medicines Policy',
            'reconciliation' => 'Medication Reconciliation',
            default => ucwords(str_replace('_', ' ', $category)),
        };
    }

    /**
     * @param array<string, mixed>|null $source
     * @return array{
     *     type:string,
     *     label:string,
     *     referenceId:string|null,
     *     referenceLabel:string|null,
     *     observedAt:string|null,
     *     flag:string|null
     * }|null
     */
    private static function normalizeSource(string $category, ?array $source): ?array
    {
        $defaultSource = self::defaultSourceForCategory($category);
        $sourceType = trim((string) ($source['type'] ?? ($defaultSource['type'] ?? '')));
        $sourceLabel = trim((string) ($source['label'] ?? ($defaultSource['label'] ?? '')));

        if ($sourceType === '' && $sourceLabel === '') {
            return null;
        }

        return [
            'type' => $sourceType !== '' ? $sourceType : 'unknown',
            'label' => $sourceLabel !== '' ? $sourceLabel : 'Clinical safety context',
            'referenceId' => self::nullableTrim($source['referenceId'] ?? null),
            'referenceLabel' => self::nullableTrim($source['referenceLabel'] ?? null),
            'observedAt' => self::nullableTrim($source['observedAt'] ?? null),
            'flag' => self::nullableTrim($source['flag'] ?? null),
        ];
    }

    /**
     * @return array{type:string,label:string}|null
     */
    private static function defaultSourceForCategory(string $category): ?array
    {
        return match ($category) {
            'allergy' => ['type' => 'patient_allergy_list', 'label' => 'Active allergy list'],
            'dose', 'indication' => ['type' => 'order_entry', 'label' => 'Order entry details'],
            'duplicate_therapy', 'history' => ['type' => 'medication_history', 'label' => 'Medication history'],
            'interaction' => ['type' => 'current_medication_context', 'label' => 'Current medication context'],
            'inventory' => ['type' => 'inventory_stock', 'label' => 'Dispense inventory'],
            'laboratory' => ['type' => 'recent_laboratory_results', 'label' => 'Recent verified laboratory results'],
            'policy' => ['type' => 'approved_medicines_policy', 'label' => 'Approved medicines governance'],
            'reconciliation' => ['type' => 'reconciliation_workspace', 'label' => 'Medication reconciliation workspace'],
            default => null,
        };
    }

    private static function nullableTrim(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
