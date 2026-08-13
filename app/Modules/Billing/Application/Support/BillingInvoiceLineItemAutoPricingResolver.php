<?php

namespace App\Modules\Billing\Application\Support;

use App\Modules\Billing\Application\Exceptions\BillingInvoicePricingResolutionException;
use App\Modules\Billing\Domain\Repositories\BillingPayerAuthorizationRuleRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractPriceOverrideRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Services\InventoryItemUnitPricingService;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;

class BillingInvoiceLineItemAutoPricingResolver
{
    public function __construct(
        private readonly ChargeableItemPricingLookup $chargeableItemPricingLookup,
        private readonly BillingPayerContractRepositoryInterface $payerContractRepository,
        private readonly BillingPayerContractPriceOverrideRepositoryInterface $payerContractPriceOverrideRepository,
        private readonly BillingPayerAuthorizationRuleRepositoryInterface $payerAuthorizationRuleRepository,
        private readonly InventoryItemUnitPricingService $inventoryItemUnitPricingService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>|null  $lineItems
     * @return array<string, mixed>
     */
    public function resolve(
        ?array $lineItems,
        string $currencyCode,
        ?string $invoiceDateTime,
        float $discountAmount,
        float $paidAmount,
        bool $autoPriceLineItems,
        ?string $billingPayerContractId
    ): array {
        $normalizedCurrency = strtoupper(trim($currencyCode));
        $normalizedPayerContractId = $this->normalizeNullableUuidLike($billingPayerContractId);
        $payerContract = null;

        if ($normalizedPayerContractId !== null) {
            $payerContract = $this->payerContractRepository->findById($normalizedPayerContractId);
            if (! $payerContract) {
                throw new BillingInvoicePricingResolutionException(
                    'billingPayerContractId',
                    'Selected billing payer contract was not found in current scope.',
                );
            }

            if (($payerContract['status'] ?? null) !== 'active') {
                throw new BillingInvoicePricingResolutionException(
                    'billingPayerContractId',
                    'Selected billing payer contract must be active for invoice pricing.',
                );
            }
        }

        if (! $autoPriceLineItems) {
            return [
                'billing_payer_contract_id' => $normalizedPayerContractId,
                'pricing_mode' => 'manual',
                'pricing_context' => [
                    'autoPricingApplied' => false,
                    'resolvedAt' => now()->toISOString(),
                    'currencyCode' => $normalizedCurrency,
                    'payerContractId' => $normalizedPayerContractId,
                ],
            ];
        }

        if (! is_array($lineItems) || count($lineItems) === 0) {
            throw new BillingInvoicePricingResolutionException(
                'lineItems',
                'lineItems is required when autoPriceLineItems is enabled.',
            );
        }

        $effectiveAt = $this->normalizeEffectiveDateTime($invoiceDateTime);
        $resolvedLineItems = [];
        $subtotalAmount = 0.0;
        $taxAmount = 0.0;
        $lineItemsRequiringAuthorization = 0;
        $lineItemsAutoApproved = 0;
        $matchedRuleCount = 0;
        $matchedRuleCodes = [];
        $lineItemsExcluded = 0;
        $lineItemsManualReview = 0;
        $lineItemsCoveredWithRule = 0;
        $lineItemsUsingPolicyRule = 0;
        $matchedCoverageRuleCount = 0;
        $matchedCoverageRuleCodes = [];
        $matchedPriceOverrideCount = 0;
        $matchedPriceOverrideServiceCodes = [];

        $serviceCodes = array_values(array_unique(array_filter(array_map(
            static fn (array $lineItem): string => strtoupper(trim((string) ($lineItem['serviceCode'] ?? ''))),
            $lineItems,
        ))));
        // PricingEngine_Migration_Plan.md Phase 5: resolves via
        // chargeable_items/price_book_entries now, not the legacy service
        // catalog -- ChargeableItemPricingLookup returns the same shape
        // the old repository method did, so everything below (override
        // application, authorization rule matching, tax calculation)
        // stays unchanged.
        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $pricingMap = [];
        foreach ($serviceCodes as $code) {
            $pricingMap[$code] = $this->chargeableItemPricingLookup->findByServiceCode(
                serviceCode: $code,
                currencyCode: $normalizedCurrency,
                asOfDateTime: $effectiveAt,
                tenantId: $tenantId,
                facilityId: $facilityId,
            );
        }

        $overrideMap = [];
        if ($payerContract !== null) {
            $overrideMap = $this->payerContractPriceOverrideRepository->findActiveApplicableOverrides(
                billingPayerContractId: (string) $payerContract['id'],
                serviceCodes: $serviceCodes,
                currencyCode: $normalizedCurrency,
                asOfDateTime: $effectiveAt,
            );
        }

        $rulesMap = [];
        if ($payerContract !== null) {
            $rulesMap = $this->payerAuthorizationRuleRepository->listActiveMatchingRulesByServiceCodes(
                billingPayerContractId: (string) $payerContract['id'],
                serviceCodes: $serviceCodes,
                asOfDateTime: $effectiveAt,
            );
        }

        foreach ($lineItems as $index => $lineItem) {
            // Reset every iteration -- these are only assigned in the catalog-priced
            // branch below, but were previously read unconditionally when building
            // the stored snapshot, so a medicine-priced line item could silently
            // inherit a preceding catalog-priced line item's values (or emit an
            // undefined-variable warning if it was the first line item).
            $catalogUnitPrice = null;
            $priceOverride = null;

            $serviceCode = strtoupper(trim((string) ($lineItem['serviceCode'] ?? '')));
            if ($serviceCode === '') {
                throw new BillingInvoicePricingResolutionException(
                    'lineItems',
                    'Every line item requires serviceCode when autoPriceLineItems is enabled.',
                );
            }

            $catalogItem = $pricingMap[$serviceCode] ?? null;

            if (! $catalogItem) {
                throw new BillingInvoicePricingResolutionException(
                    'lineItems',
                    sprintf('No active service catalog pricing found for serviceCode %s in %s.', $serviceCode, $normalizedCurrency),
                );
            }

            $quantity = round(max((float) ($lineItem['quantity'] ?? 0), 0), 2);
            if ($quantity <= 0) {
                throw new BillingInvoicePricingResolutionException(
                    'lineItems',
                    sprintf('Line item at position %d must have quantity greater than zero.', $index + 1),
                );
            }

            $medicineUnitPrice = $this->resolveMedicineUnitPrice(
                catalogItem: $catalogItem,
                lineItem: $lineItem,
                currencyCode: $normalizedCurrency,
                payerContract: $payerContract,
                effectiveAt: $effectiveAt,
            );

            if ($medicineUnitPrice !== null) {
                $unitPrice = $medicineUnitPrice['price'];
                $pricingSource = $medicineUnitPrice['source'];
                $pricingSourceId = $medicineUnitPrice['sourceId'] ?? null;
            } else {
                $catalogUnitPrice = round((float) ($catalogItem['base_price'] ?? 0), 2);
                $priceOverride = null;
                $unitPrice = $catalogUnitPrice;
                $pricingSource = 'service_catalog';
                $pricingSourceId = $catalogItem['id'] ?? null;

                if ($payerContract !== null) {
                    $priceOverride = $overrideMap[$serviceCode] ?? null;

                    if ($priceOverride !== null) {
                        $unitPrice = $this->resolveOverriddenUnitPrice($catalogUnitPrice, $priceOverride);
                        $pricingSource = 'payer_contract_price_override';
                        $pricingSourceId = $priceOverride['id'] ?? null;
                        $matchedPriceOverrideCount++;
                        $matchedPriceOverrideServiceCodes[] = $serviceCode;
                    }
                }
            }

            $lineSubtotal = round($quantity * $unitPrice, 2);
            $lineTax = $this->calculateLineTax($catalogItem, $lineSubtotal);
            $taxAmount += $lineTax;
            $subtotalAmount += $lineSubtotal;

            $authorization = [
                'required' => false,
                'autoApproved' => false,
                'matchedRuleIds' => [],
                'matchedRuleCodes' => [],
                'matchedRuleCount' => 0,
            ];
            $coverage = null;

            if ($payerContract !== null) {
                $matchingRules = array_values(array_filter(
                    array_merge(
                        $rulesMap[''] ?? [],
                        $rulesMap[$serviceCode] ?? [],
                    ),
                    fn (array $rule): bool => $this->isServiceLevelRuleMatch(
                        rule: $rule,
                        serviceCode: $serviceCode,
                        serviceType: isset($catalogItem['service_type']) ? (string) $catalogItem['service_type'] : null,
                        department: isset($catalogItem['department']) ? (string) $catalogItem['department'] : null,
                    ),
                ));

                $applicableRules = array_values(array_filter(
                    $matchingRules,
                    fn (array $rule): bool => $this->isAuthorizationRuleApplicable(
                        rule: $rule,
                        quantity: $quantity,
                        lineSubtotal: $lineSubtotal,
                    ),
                ));

                $coverage = $this->buildCoverageSummary($applicableRules, $payerContract);
                $authorization = $this->buildAuthorizationSummary($applicableRules);
                $matchedRuleCount += (int) $authorization['matchedRuleCount'];
                $matchedRuleCodes = array_merge($matchedRuleCodes, $authorization['matchedRuleCodes']);
                $matchedCoverageRuleCount += (int) ($coverage['matchedRuleCount'] ?? 0);
                $matchedCoverageRuleCodes = array_merge($matchedCoverageRuleCodes, (array) ($coverage['matchedRuleCodes'] ?? []));

                if ($authorization['required']) {
                    $lineItemsRequiringAuthorization++;
                }

                if ($authorization['autoApproved']) {
                    $lineItemsAutoApproved++;
                }

                if (($coverage['decision'] ?? null) === 'excluded') {
                    $lineItemsExcluded++;
                }

                if (($coverage['decision'] ?? null) === 'manual_review') {
                    $lineItemsManualReview++;
                }

                if (($coverage['decision'] ?? null) === 'covered_with_rule') {
                    $lineItemsCoveredWithRule++;
                }

                if (($coverage['source'] ?? null) === 'policy_rule') {
                    $lineItemsUsingPolicyRule++;
                }
            }

            $description = trim((string) ($lineItem['description'] ?? ''));
            if ($description === '') {
                $description = (string) ($catalogItem['service_name'] ?? $serviceCode);
            }

            $resolvedLineItems[] = [
                'description' => $description,
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'lineTotal' => $lineSubtotal,
                'serviceCode' => $serviceCode,
                'departmentId' => $catalogItem['department_id'] ?? null,
                'department' => $catalogItem['department'] ?? null,
                'unit' => $lineItem['unit'] ?? ($catalogItem['unit'] ?? null),
                'notes' => $lineItem['notes'] ?? null,
                'sourceWorkflowKind' => $this->normalizeNullableString($lineItem['sourceWorkflowKind'] ?? null),
                'sourceWorkflowId' => $this->normalizeNullableString($lineItem['sourceWorkflowId'] ?? null),
                'sourceWorkflowLabel' => $this->normalizeNullableString($lineItem['sourceWorkflowLabel'] ?? null),
                'sourcePerformedAt' => $this->normalizeNullableString($lineItem['sourcePerformedAt'] ?? null),
                'pricingSource' => $pricingSource,
                'pricingSourceId' => $pricingSourceId,
                'catalogServiceName' => $catalogItem['service_name'] ?? null,
                'catalogUnitPrice' => $catalogUnitPrice,
                'priceOverride' => $this->buildPriceOverrideSummary($priceOverride),
                'coverage' => $coverage,
                'authorization' => $authorization,
            ];
        }

        $discountAmount = round(max($discountAmount, 0), 2);
        $subtotalAmount = round($subtotalAmount, 2);
        $taxAmount = round($taxAmount, 2);
        $totalAmount = round(max(($subtotalAmount - $discountAmount) + $taxAmount, 0), 2);
        $paidAmount = round(min(max($paidAmount, 0), $totalAmount), 2);
        $balanceAmount = round(max($totalAmount - $paidAmount, 0), 2);
        $uniqueMatchedRuleCodes = array_values(array_unique(array_filter(
            array_map(static fn ($value): string => is_string($value) ? trim($value) : '', $matchedRuleCodes),
        )));
        $uniqueMatchedCoverageRuleCodes = array_values(array_unique(array_filter(
            array_map(static fn ($value): string => is_string($value) ? trim($value) : '', $matchedCoverageRuleCodes),
        )));

        return [
            'line_items' => $resolvedLineItems,
            'subtotal_amount' => $subtotalAmount,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'balance_amount' => $balanceAmount,
            'billing_payer_contract_id' => $normalizedPayerContractId,
            'pricing_mode' => 'service_catalog',
            'pricing_context' => [
                'autoPricingApplied' => true,
                'resolvedAt' => now()->toISOString(),
                'effectiveAt' => $effectiveAt,
                'currencyCode' => $normalizedCurrency,
                'payerContractId' => $normalizedPayerContractId,
                'priceOverrideSummary' => [
                    'matchedOverrideCount' => $matchedPriceOverrideCount,
                    'matchedServiceCodes' => array_values(array_unique($matchedPriceOverrideServiceCodes)),
                ],
                'authorizationSummary' => [
                    'lineItemsRequiringAuthorization' => $lineItemsRequiringAuthorization,
                    'lineItemsAutoApproved' => $lineItemsAutoApproved,
                    'matchedRuleCount' => $matchedRuleCount,
                    'matchedRuleCodes' => $uniqueMatchedRuleCodes,
                ],
                'coverageSummary' => [
                    'lineItemsExcluded' => $lineItemsExcluded,
                    'lineItemsManualReview' => $lineItemsManualReview,
                    'lineItemsCoveredWithRule' => $lineItemsCoveredWithRule,
                    'lineItemsUsingPolicyRule' => $lineItemsUsingPolicyRule,
                    'matchedRuleCount' => $matchedCoverageRuleCount,
                    'matchedRuleCodes' => $uniqueMatchedCoverageRuleCodes,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function resolveOverriddenUnitPrice(float $catalogUnitPrice, array $override): float
    {
        $strategy = strtolower(trim((string) ($override['pricing_strategy'] ?? 'fixed_price')));
        $overrideValue = round(max((float) ($override['override_value'] ?? 0), 0), 2);

        $resolved = match ($strategy) {
            'discount_percent' => round($catalogUnitPrice * max(1 - ($overrideValue / 100), 0), 2),
            'markup_percent' => round($catalogUnitPrice * (1 + ($overrideValue / 100)), 2),
            default => $overrideValue,
        };

        return round(max($resolved, 0), 2);
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>|null
     */
    private function buildPriceOverrideSummary(?array $override): ?array
    {
        if ($override === null) {
            return null;
        }

        return [
            'id' => $override['id'] ?? null,
            'serviceCode' => $override['service_code'] ?? null,
            'serviceName' => $override['service_name'] ?? null,
            'pricingStrategy' => $override['pricing_strategy'] ?? null,
            'overrideValue' => $override['override_value'] ?? null,
            'effectiveFrom' => $override['effective_from'] ?? null,
            'effectiveTo' => $override['effective_to'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<string, mixed>  $payerContract
     * @return array<string, mixed>
     */
    private function buildCoverageSummary(array $rules, array $payerContract): array
    {
        $matchedRuleIds = array_values(array_filter(array_map(
            static fn (array $rule): ?string => isset($rule['id']) ? (string) $rule['id'] : null,
            $rules,
        )));
        $matchedRuleCodes = array_values(array_filter(array_map(
            static fn (array $rule): ?string => isset($rule['rule_code']) ? (string) $rule['rule_code'] : null,
            $rules,
        )));

        $defaultCoveragePercent = isset($payerContract['default_coverage_percent'])
            ? round((float) $payerContract['default_coverage_percent'], 2)
            : null;
        $defaultCopayType = $this->normalizeNullableString($payerContract['default_copay_type'] ?? null);
        $defaultCopayValue = isset($payerContract['default_copay_value'])
            ? round((float) $payerContract['default_copay_value'], 2)
            : null;

        $selectedRule = null;
        foreach ($rules as $rule) {
            if ($selectedRule === null || $this->isHigherCoveragePriorityRule($rule, $selectedRule)) {
                $selectedRule = $rule;
            }
        }

        if ($selectedRule === null) {
            return [
                'decision' => 'inherit',
                'source' => 'contract_default',
                'selectedRuleId' => null,
                'selectedRuleCode' => null,
                'selectedRuleName' => null,
                'effectiveCoveragePercent' => $defaultCoveragePercent,
                'copayType' => $defaultCopayType,
                'copayValue' => $defaultCopayValue,
                'benefitLimitAmount' => null,
                'matchedRuleIds' => [],
                'matchedRuleCodes' => [],
                'matchedRuleCount' => 0,
            ];
        }

        $decision = $this->normalizeCoverageDecision($selectedRule['coverage_decision'] ?? null);
        $coveragePercent = $defaultCoveragePercent;
        $copayType = $defaultCopayType;
        $copayValue = $defaultCopayValue;
        $benefitLimitAmount = isset($selectedRule['benefit_limit_amount'])
            ? round((float) $selectedRule['benefit_limit_amount'], 2)
            : null;

        if ($decision === 'excluded') {
            $coveragePercent = 0.0;
            $copayType = null;
            $copayValue = null;
        } elseif ($decision === 'manual_review') {
            $coveragePercent = null;
        } else {
            if (isset($selectedRule['coverage_percent_override']) && $selectedRule['coverage_percent_override'] !== null) {
                $coveragePercent = round((float) $selectedRule['coverage_percent_override'], 2);
            }

            if (array_key_exists('copay_type', $selectedRule) && $selectedRule['copay_type'] !== null) {
                $copayType = $this->normalizeNullableString($selectedRule['copay_type']);
                if ($copayType === 'none') {
                    $copayValue = null;
                } elseif (isset($selectedRule['copay_value']) && $selectedRule['copay_value'] !== null) {
                    $copayValue = round((float) $selectedRule['copay_value'], 2);
                }
            }
        }

        return [
            'decision' => $decision,
            'source' => 'policy_rule',
            'selectedRuleId' => $selectedRule['id'] ?? null,
            'selectedRuleCode' => $selectedRule['rule_code'] ?? null,
            'selectedRuleName' => $selectedRule['rule_name'] ?? null,
            'effectiveCoveragePercent' => $coveragePercent,
            'copayType' => $copayType,
            'copayValue' => $copayValue,
            'benefitLimitAmount' => $benefitLimitAmount,
            'matchedRuleIds' => $matchedRuleIds,
            'matchedRuleCodes' => $matchedRuleCodes,
            'matchedRuleCount' => count($rules),
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    /**
     * @param  array<string, mixed>  $rule
     */
    private function isServiceLevelRuleMatch(array $rule, string $serviceCode, ?string $serviceType, ?string $department): bool
    {
        $ruleServiceCode = $this->normalizeNullableString($rule['service_code'] ?? null);
        if ($ruleServiceCode !== null && $ruleServiceCode !== $serviceCode) {
            return false;
        }

        $ruleServiceType = $this->normalizeNullableString($rule['service_type'] ?? null);
        if ($ruleServiceType !== null && $ruleServiceType !== $serviceType) {
            return false;
        }

        $ruleDepartment = $this->normalizeNullableString($rule['department'] ?? null);
        if ($ruleDepartment !== null && $ruleDepartment !== $department) {
            return false;
        }

        return true;
    }

    private function isAuthorizationRuleApplicable(array $rule, float $quantity, float $lineSubtotal): bool
    {
        $quantityLimit = $rule['quantity_limit'] ?? null;
        if ($quantityLimit !== null && $quantity > (float) $quantityLimit) {
            return false;
        }

        $amountThreshold = $rule['amount_threshold'] ?? null;
        if ($amountThreshold !== null && $lineSubtotal < (float) $amountThreshold) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function buildAuthorizationSummary(array $rules): array
    {
        if ($rules === []) {
            return [
                'required' => false,
                'autoApproved' => false,
                'matchedRuleIds' => [],
                'matchedRuleCodes' => [],
                'matchedRuleCount' => 0,
            ];
        }

        $requiringRules = array_values(array_filter(
            $rules,
            static fn (array $rule): bool => (bool) ($rule['requires_authorization'] ?? false),
        ));
        $requiresAuthorization = $requiringRules !== [];
        $autoApproved = $requiresAuthorization
            ? count(array_filter(
                $requiringRules,
                static fn (array $rule): bool => ! (bool) ($rule['auto_approve'] ?? false),
            )) === 0
            : false;

        $matchedRuleIds = array_values(array_map(
            static fn (array $rule): ?string => isset($rule['id']) ? (string) $rule['id'] : null,
            $rules,
        ));
        $matchedRuleCodes = array_values(array_map(
            static fn (array $rule): ?string => isset($rule['rule_code']) ? (string) $rule['rule_code'] : null,
            $rules,
        ));

        return [
            'required' => $requiresAuthorization,
            'autoApproved' => $autoApproved,
            'matchedRuleIds' => array_values(array_filter($matchedRuleIds)),
            'matchedRuleCodes' => array_values(array_filter($matchedRuleCodes)),
            'matchedRuleCount' => count($rules),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $current
     */
    private function isHigherCoveragePriorityRule(array $candidate, array $current): bool
    {
        $candidatePriority = $this->coverageDecisionPriority($this->normalizeCoverageDecision($candidate['coverage_decision'] ?? null));
        $currentPriority = $this->coverageDecisionPriority($this->normalizeCoverageDecision($current['coverage_decision'] ?? null));

        if ($candidatePriority !== $currentPriority) {
            return $candidatePriority > $currentPriority;
        }

        $candidateSpecificity = $this->coverageRuleSpecificityScore($candidate);
        $currentSpecificity = $this->coverageRuleSpecificityScore($current);

        if ($candidateSpecificity !== $currentSpecificity) {
            return $candidateSpecificity > $currentSpecificity;
        }

        return strcmp((string) ($candidate['updated_at'] ?? ''), (string) ($current['updated_at'] ?? '')) > 0;
    }

    private function normalizeCoverageDecision(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['inherit', 'covered', 'covered_with_rule', 'excluded', 'manual_review'], true)
            ? $normalized
            : 'covered_with_rule';
    }

    private function coverageDecisionPriority(string $decision): int
    {
        return match ($decision) {
            'excluded' => 5,
            'manual_review' => 4,
            'covered_with_rule' => 3,
            'covered' => 2,
            'inherit' => 1,
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function coverageRuleSpecificityScore(array $rule): int
    {
        $score = 0;

        foreach ([
            'billing_service_catalog_item_id' => 6,
            'service_code' => 5,
            'service_type' => 4,
            'department' => 3,
            'diagnosis_code' => 3,
            'priority' => 2,
            'min_patient_age_years' => 2,
            'max_patient_age_years' => 2,
            'gender' => 1,
            'amount_threshold' => 1,
            'quantity_limit' => 1,
        ] as $field => $weight) {
            if (($rule[$field] ?? null) !== null && trim((string) $rule[$field]) !== '') {
                $score += $weight;
            }
        }

        return $score;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $catalogItem
     */
    private function calculateLineTax(array $catalogItem, float $lineSubtotal): float
    {
        if (! (bool) ($catalogItem['is_taxable'] ?? false)) {
            return 0.0;
        }

        $taxRate = max((float) ($catalogItem['tax_rate_percent'] ?? 0), 0);
        if ($taxRate <= 0) {
            return 0.0;
        }

        return round($lineSubtotal * ($taxRate / 100), 2);
    }

    private function normalizeNullableUuidLike(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function resolveMedicineUnitPrice(array $catalogItem, array $lineItem, string $currencyCode, ?array $payerContract, string $effectiveAt): ?array
    {
        $inventoryItemId = $this->normalizeNullableUuidLike($catalogItem['inventory_item_id'] ?? null)
            ?? $this->normalizeNullableUuidLike($lineItem['inventoryItemId'] ?? null)
            ?? $this->normalizeNullableUuidLike($lineItem['inventory_item_id'] ?? null);

        if ($inventoryItemId === null) {
            return null;
        }

        $unitId = $this->normalizeNullableUuidLike($lineItem['inventoryItemUnitId'] ?? null)
            ?? $this->normalizeNullableUuidLike($lineItem['unitId'] ?? null);

        $priceType = $this->resolvePriceTypeForPayerContract($payerContract);

        try {
            $resolved = $this->inventoryItemUnitPricingService->resolvePrice(
                itemId: $inventoryItemId,
                unitId: $unitId,
                priceType: $priceType,
                currencyCode: $currencyCode,
                billingPayerContractId: $payerContract['id'] ?? null,
                effectiveAt: $effectiveAt,
            );
        } catch (\Throwable) {
            return null;
        }

        return [
            'price' => (float) ($resolved['price'] ?? 0),
            'source' => $priceType === 'insurance' || $priceType === 'contract' ? 'inventory_item_unit_payer_price' : 'inventory_item_unit_price',
            'sourceId' => $resolved['id'] ?? null,
        ];
    }

    private function resolvePriceTypeForPayerContract(?array $payerContract): string
    {
        if ($payerContract === null) {
            return 'retail';
        }

        $normalized = strtolower(trim((string) ($payerContract['contract_type'] ?? '')));

        return match ($normalized) {
            'insurance', 'insurrance', 'nhif', 'insurance_contract' => 'insurance',
            'corporate', 'wholesale', 'b2b' => 'wholesale',
            default => 'contract',
        };
    }

    private function normalizeEffectiveDateTime(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return now()->toDateTimeString();
        }

        return trim($value);
    }
}
