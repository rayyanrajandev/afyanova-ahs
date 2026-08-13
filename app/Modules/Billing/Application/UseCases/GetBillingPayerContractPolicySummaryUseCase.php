<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPayerAuthorizationRuleRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;

class GetBillingPayerContractPolicySummaryUseCase
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $contractRepository,
        private readonly BillingPayerAuthorizationRuleRepositoryInterface $ruleRepository,
    ) {}

    public function execute(string $billingPayerContractId): ?array
    {
        $contract = $this->contractRepository->findById($billingPayerContractId);
        if (! $contract) {
            return null;
        }

        $activeRules = $this->ruleRepository->listByContractId(
            billingPayerContractId: $billingPayerContractId,
            status: 'active',
        );

        return [
            'overview' => $this->buildOverview($activeRules),
            'familyMatrix' => $this->buildFamilyMatrix($activeRules),
            'benefitBands' => $this->buildBenefitBands($activeRules),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, int>
     */
    private function buildOverview(array $rules): array
    {
        $familyKeys = [];
        $excludedPolicies = 0;
        $manualReviewPolicies = 0;
        $coveredPolicies = 0;
        $authorizationRequiredPolicies = 0;
        $autoApprovePolicies = 0;
        $benefitBandPolicies = 0;

        foreach ($rules as $rule) {
            $familyKey = $this->resolveFamilyKey($rule);
            if ($this->isActualServiceFamily($familyKey)) {
                $familyKeys[$familyKey] = true;
            }

            $decision = $this->normalizeCoverageDecision($rule['coverage_decision'] ?? null);
            if ($decision === 'excluded') {
                $excludedPolicies++;
            }
            if ($decision === 'manual_review') {
                $manualReviewPolicies++;
            }
            if (in_array($decision, ['covered', 'covered_with_rule'], true)) {
                $coveredPolicies++;
            }
            if (($rule['requires_authorization'] ?? false) === true) {
                $authorizationRequiredPolicies++;
            }
            if (($rule['auto_approve'] ?? false) === true) {
                $autoApprovePolicies++;
            }
            if ($this->isBenefitBandPolicy($rule)) {
                $benefitBandPolicies++;
            }
        }

        return [
            'activePolicies' => count($rules),
            'serviceFamilies' => count($familyKeys),
            'coveredPolicies' => $coveredPolicies,
            'excludedPolicies' => $excludedPolicies,
            'manualReviewPolicies' => $manualReviewPolicies,
            'authorizationRequiredPolicies' => $authorizationRequiredPolicies,
            'autoApprovePolicies' => $autoApprovePolicies,
            'benefitBandPolicies' => $benefitBandPolicies,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function buildFamilyMatrix(array $rules): array
    {
        $groups = [];

        foreach ($rules as $rule) {
            $familyKey = $this->resolveFamilyKey($rule);
            if (! array_key_exists($familyKey, $groups)) {
                $groups[$familyKey] = [
                    'key' => $familyKey,
                    'label' => $this->formatFamilyLabel($familyKey),
                    'policyCount' => 0,
                    'specificServiceCount' => 0,
                    'coveredPolicyCount' => 0,
                    'excludedPolicyCount' => 0,
                    'manualReviewPolicyCount' => 0,
                    'requiresAuthorizationCount' => 0,
                    'autoApproveCount' => 0,
                    'benefitBandCount' => 0,
                    'windowedPolicyCount' => 0,
                    'dominantDecision' => 'inherit',
                    'coverageOverrideMin' => null,
                    'coverageOverrideMax' => null,
                    '_decisionPriority' => PHP_INT_MAX,
                    '_coverageOverrides' => [],
                ];
            }

            $group = &$groups[$familyKey];
            $group['policyCount']++;

            if ($this->normalizeNullableString($rule['service_code'] ?? null) !== null) {
                $group['specificServiceCount']++;
            }

            $decision = $this->normalizeCoverageDecision($rule['coverage_decision'] ?? null);
            if (in_array($decision, ['covered', 'covered_with_rule'], true)) {
                $group['coveredPolicyCount']++;
            }
            if ($decision === 'excluded') {
                $group['excludedPolicyCount']++;
            }
            if ($decision === 'manual_review') {
                $group['manualReviewPolicyCount']++;
            }
            if (($rule['requires_authorization'] ?? false) === true) {
                $group['requiresAuthorizationCount']++;
            }
            if (($rule['auto_approve'] ?? false) === true) {
                $group['autoApproveCount']++;
            }
            if ($this->isBenefitBandPolicy($rule)) {
                $group['benefitBandCount']++;
            }
            if ($this->isWindowedPolicy($rule)) {
                $group['windowedPolicyCount']++;
            }

            $coverageOverride = $this->normalizeNullableNumeric($rule['coverage_percent_override'] ?? null);
            if ($coverageOverride !== null) {
                $group['_coverageOverrides'][] = $coverageOverride;
            }

            $decisionPriority = $this->coverageDecisionPriority($decision);
            if ($decisionPriority < $group['_decisionPriority']) {
                $group['_decisionPriority'] = $decisionPriority;
                $group['dominantDecision'] = $decision;
            }

            unset($group);
        }

        $matrix = array_map(function (array $group): array {
            $coverageOverrides = $group['_coverageOverrides'];
            sort($coverageOverrides);
            $group['coverageOverrideMin'] = count($coverageOverrides) > 0 ? number_format($coverageOverrides[0], 2, '.', '') : null;
            $group['coverageOverrideMax'] = count($coverageOverrides) > 0 ? number_format($coverageOverrides[count($coverageOverrides) - 1], 2, '.', '') : null;
            unset($group['_coverageOverrides'], $group['_decisionPriority']);

            return $group;
        }, array_values($groups));

        usort($matrix, function (array $left, array $right): int {
            $leftRank = $this->familySortRank((string) $left['key']);
            $rightRank = $this->familySortRank((string) $right['key']);

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcmp((string) $left['label'], (string) $right['label']);
        });

        return $matrix;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function buildBenefitBands(array $rules): array
    {
        $benefitBands = [];

        foreach ($rules as $rule) {
            if (! $this->isBenefitBandPolicy($rule)) {
                continue;
            }

            $benefitBands[] = [
                'ruleId' => $rule['id'] ?? null,
                'ruleCode' => $rule['rule_code'] ?? null,
                'ruleName' => $rule['rule_name'] ?? null,
                'serviceType' => $rule['service_type'] ?? null,
                'serviceCode' => $rule['service_code'] ?? null,
                'department' => $rule['department'] ?? null,
                'coverageDecision' => $this->normalizeCoverageDecision($rule['coverage_decision'] ?? null),
                'coveragePercentOverride' => $this->normalizeNullableDecimalString($rule['coverage_percent_override'] ?? null),
                'copayType' => $this->normalizeNullableString($rule['copay_type'] ?? null),
                'copayValue' => $this->normalizeNullableDecimalString($rule['copay_value'] ?? null),
                'amountThreshold' => $this->normalizeNullableDecimalString($rule['amount_threshold'] ?? null),
                'quantityLimit' => $rule['quantity_limit'] ?? null,
                'benefitLimitAmount' => $this->normalizeNullableDecimalString($rule['benefit_limit_amount'] ?? null),
                'effectiveFrom' => $this->normalizeNullableDateTime($rule['effective_from'] ?? null),
                'effectiveTo' => $this->normalizeNullableDateTime($rule['effective_to'] ?? null),
                'requiresAuthorization' => ($rule['requires_authorization'] ?? false) === true,
                'autoApprove' => ($rule['auto_approve'] ?? false) === true,
            ];
        }

        usort($benefitBands, function (array $left, array $right): int {
            $leftLabel = (string) ($left['serviceType'] ?? '');
            $rightLabel = (string) ($right['serviceType'] ?? '');
            if ($leftLabel !== $rightLabel) {
                return strcmp($leftLabel, $rightLabel);
            }

            return strcmp((string) ($left['ruleCode'] ?? ''), (string) ($right['ruleCode'] ?? ''));
        });

        return $benefitBands;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function resolveFamilyKey(array $rule): string
    {
        $serviceType = $this->normalizeNullableString($rule['service_type'] ?? null);
        if ($serviceType !== null) {
            return $serviceType;
        }

        if ($this->normalizeNullableString($rule['service_code'] ?? null) !== null) {
            return '__service_specific__';
        }

        return '__all_services__';
    }

    private function formatFamilyLabel(string $key): string
    {
        return match ($key) {
            '__all_services__' => 'All services',
            '__service_specific__' => 'Service-specific',
            default => $this->formatEnumLabel($key),
        };
    }

    private function familySortRank(string $key): int
    {
        return match ($key) {
            '__all_services__' => 0,
            '__service_specific__' => 2,
            default => 1,
        };
    }

    private function isActualServiceFamily(string $key): bool
    {
        return ! in_array($key, ['__all_services__', '__service_specific__'], true);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function isBenefitBandPolicy(array $rule): bool
    {
        return $this->normalizeNullableDecimalString($rule['amount_threshold'] ?? null) !== null
            || ($rule['quantity_limit'] ?? null) !== null
            || $this->normalizeNullableDecimalString($rule['benefit_limit_amount'] ?? null) !== null
            || $this->isWindowedPolicy($rule)
            || $this->hasCopay($rule);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function hasCopay(array $rule): bool
    {
        $copayType = $this->normalizeNullableString($rule['copay_type'] ?? null);

        return $copayType !== null && $copayType !== 'none';
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function isWindowedPolicy(array $rule): bool
    {
        return $this->normalizeNullableDateTime($rule['effective_from'] ?? null) !== null
            || $this->normalizeNullableDateTime($rule['effective_to'] ?? null) !== null;
    }

    private function normalizeCoverageDecision(mixed $value): string
    {
        $normalized = strtolower($this->normalizeNullableString($value) ?? '');

        return in_array($normalized, ['inherit', 'covered', 'covered_with_rule', 'excluded', 'manual_review'], true)
            ? $normalized
            : 'covered_with_rule';
    }

    private function coverageDecisionPriority(string $decision): int
    {
        return match ($decision) {
            'excluded' => 0,
            'manual_review' => 1,
            'covered_with_rule' => 2,
            'covered' => 3,
            default => 4,
        };
    }

    private function formatEnumLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Unknown';
        }

        return ucwords(str_replace('_', ' ', $value));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeNullableNumeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function normalizeNullableDecimalString(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableNumeric($value);

        return $normalized === null ? null : number_format($normalized, 2, '.', '');
    }

    private function normalizeNullableDateTime(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableString($value);

        return $normalized === null ? null : $normalized;
    }
}
