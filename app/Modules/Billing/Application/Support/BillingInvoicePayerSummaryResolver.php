<?php

namespace App\Modules\Billing\Application\Support;

use App\Modules\Billing\Application\Exceptions\BillingInvoicePricingResolutionException;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use Carbon\CarbonImmutable;

class BillingInvoicePayerSummaryResolver
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $payerContractRepository,
    ) {}

    // ═══════════════════════════════════════════════════════════════
    // PURE MAPPING PATH: driven by an explicit BillingDecision DTO.
    // No DB queries for contract status. No validation. No branching.
    // All decisions were already made in the orchestration layer.
    // ═══════════════════════════════════════════════════════════════

    /**
     * @param  array<string, mixed>  $billingDecision
     * @param  array<string, mixed>|null  $pricingContext
     * @return array<string, mixed>
     */
    public function resolveFromBillingDecision(
        array $billingDecision,
        string $currencyCode,
        float $totalAmount,
        ?string $invoiceDateTime,
        ?array $pricingContext = null,
    ): array {
        $normalizedCurrencyCode = strtoupper(trim($currencyCode));
        $effectiveInvoiceDate = $this->normalizeInvoiceDate($invoiceDateTime);
        $normalizedTotalAmount = round(max($totalAmount, 0), 2);
        $normalizedPricingContext = is_array($pricingContext) ? $pricingContext : [];
        $authorizationSummary = $this->normalizeAuthorizationSummary(
            is_array($normalizedPricingContext['authorizationSummary'] ?? null)
                ? $normalizedPricingContext['authorizationSummary']
                : null,
        );
        $coverageSummary = $this->normalizeCoverageSummary(
            is_array($normalizedPricingContext['coverageSummary'] ?? null)
                ? $normalizedPricingContext['coverageSummary']
                : null,
        );

        $mode = (string) ($billingDecision['mode'] ?? 'TRUE_SELF_PAY');

        if ($mode === 'INSURANCE_ACTIVE') {
            $contractId = (string) ($billingDecision['contractId'] ?? '');

            if ($contractId === '') {
                $normalizedPricingContext['payerSummary'] = $this->selfPayPayerSummary(
                    totalAmount: $normalizedTotalAmount,
                    currencyCode: $normalizedCurrencyCode,
                );
                $normalizedPricingContext['claimReadiness'] = $this->selfPayClaimReadiness(
                    totalAmount: $normalizedTotalAmount,
                    authorizationSummary: $authorizationSummary,
                    coverageSummary: $coverageSummary,
                );
                $normalizedPricingContext['payerSummaryResolvedAt'] = now()->toISOString();

                return $normalizedPricingContext;
            }

            $payerContract = $this->payerContractRepository->findById($contractId);
            if (! is_array($payerContract)) {
                throw new BillingInvoicePricingResolutionException(
                    'billingPayerContractId',
                    'Payer contract specified in BillingDecision was not found in current scope.',
                );
            }

            $this->assertCurrencyAlignment($payerContract, $normalizedCurrencyCode);
            $this->assertEffectiveOnInvoiceDate($payerContract, $effectiveInvoiceDate);

            $payerSummary = $this->contractPayerSummary(
                payerContract: $payerContract,
                totalAmount: $normalizedTotalAmount,
                currencyCode: $normalizedCurrencyCode,
                invoiceDate: $effectiveInvoiceDate,
            );

            $normalizedPricingContext['payerSummary'] = $payerSummary;
            $normalizedPricingContext['claimReadiness'] = $this->claimReadiness(
                payerSummary: $payerSummary,
                authorizationSummary: $authorizationSummary,
                coverageSummary: $coverageSummary,
            );
            $normalizedPricingContext['payerSummaryResolvedAt'] = now()->toISOString();

            return $normalizedPricingContext;
        }

        // TRUE_SELF_PAY and INSURANCE_FALLBACK_SELF_PAY → self-pay payer summary
        $normalizedPricingContext['payerSummary'] = $this->selfPayPayerSummary(
            totalAmount: $normalizedTotalAmount,
            currencyCode: $normalizedCurrencyCode,
        );

        $claimReadiness = $this->selfPayClaimReadiness(
            totalAmount: $normalizedTotalAmount,
            authorizationSummary: $authorizationSummary,
            coverageSummary: $coverageSummary,
        );

        if ($mode === 'INSURANCE_FALLBACK_SELF_PAY') {
            $claimReadiness['coverageVerificationRequired'] = true;
            $claimReadiness['state'] = 'not_applicable';
            $claimReadiness['claimEligible'] = false;
            $claimReadiness['ready'] = false;
            $claimReadiness['blockingReasons'] = [
                'Payer contract is inactive or missing — verify coverage before issuing.',
            ];
        }

        $normalizedPricingContext['claimReadiness'] = $claimReadiness;
        $normalizedPricingContext['payerSummaryResolvedAt'] = now()->toISOString();

        return $normalizedPricingContext;
    }

    // ═══════════════════════════════════════════════════════════════
    // LEGACY PATH: manual invoice creation without BillingDecision.
    // Performs contract lookup + validation as it always has.
    // Used when CreateBillingInvoiceUseCase is called outside of
    // the auto-capture orchestration layer.
    // ═══════════════════════════════════════════════════════════════

    /**
     * @param  array<string, mixed>|null  $pricingContext
     * @return array<string, mixed>
     */
    public function resolveFromLegacyInvoice(
        ?string $billingPayerContractId,
        string $currencyCode,
        float $totalAmount,
        ?string $invoiceDateTime,
        ?array $pricingContext = null,
    ): array {
        $normalizedCurrencyCode = strtoupper(trim($currencyCode));
        $effectiveInvoiceDate = $this->normalizeInvoiceDate($invoiceDateTime);
        $normalizedTotalAmount = round(max($totalAmount, 0), 2);
        $normalizedPricingContext = is_array($pricingContext) ? $pricingContext : [];
        $authorizationSummary = $this->normalizeAuthorizationSummary(
            is_array($normalizedPricingContext['authorizationSummary'] ?? null)
                ? $normalizedPricingContext['authorizationSummary']
                : null,
        );
        $coverageSummary = $this->normalizeCoverageSummary(
            is_array($normalizedPricingContext['coverageSummary'] ?? null)
                ? $normalizedPricingContext['coverageSummary']
                : null,
        );

        $contractId = $this->normalizeNullableString($billingPayerContractId);
        if ($contractId === null) {
            $normalizedPricingContext['payerSummary'] = $this->selfPayPayerSummary(
                totalAmount: $normalizedTotalAmount,
                currencyCode: $normalizedCurrencyCode,
            );
            $normalizedPricingContext['claimReadiness'] = $this->selfPayClaimReadiness(
                totalAmount: $normalizedTotalAmount,
                authorizationSummary: $authorizationSummary,
                coverageSummary: $coverageSummary,
            );
            $normalizedPricingContext['payerSummaryResolvedAt'] = now()->toISOString();

            return $normalizedPricingContext;
        }

        $payerContract = $this->payerContractRepository->findById($contractId);
        if (! is_array($payerContract)) {
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

        $this->assertCurrencyAlignment($payerContract, $normalizedCurrencyCode);
        $this->assertEffectiveOnInvoiceDate($payerContract, $effectiveInvoiceDate);

        $payerSummary = $this->contractPayerSummary(
            payerContract: $payerContract,
            totalAmount: $normalizedTotalAmount,
            currencyCode: $normalizedCurrencyCode,
            invoiceDate: $effectiveInvoiceDate,
        );

        $normalizedPricingContext['payerSummary'] = $payerSummary;
        $normalizedPricingContext['claimReadiness'] = $this->claimReadiness(
            payerSummary: $payerSummary,
            authorizationSummary: $authorizationSummary,
            coverageSummary: $coverageSummary,
        );
        $normalizedPricingContext['payerSummaryResolvedAt'] = now()->toISOString();

        return $normalizedPricingContext;
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPERS (shared across both paths)
    // ═══════════════════════════════════════════════════════════════

    /**
     * @param  array<string, mixed>|null  $authorizationSummary
     * @return array<string, mixed>
     */
    private function normalizeAuthorizationSummary(?array $authorizationSummary): array
    {
        return [
            'lineItemsRequiringAuthorization' => max((int) ($authorizationSummary['lineItemsRequiringAuthorization'] ?? 0), 0),
            'lineItemsAutoApproved' => max((int) ($authorizationSummary['lineItemsAutoApproved'] ?? 0), 0),
            'matchedRuleCount' => max((int) ($authorizationSummary['matchedRuleCount'] ?? 0), 0),
            'matchedRuleCodes' => array_values(array_filter(
                array_map(
                    fn (mixed $value): string => trim((string) $value),
                    is_array($authorizationSummary['matchedRuleCodes'] ?? null)
                        ? $authorizationSummary['matchedRuleCodes']
                        : [],
                ),
                static fn (string $value): bool => $value !== '',
            )),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $coverageSummary
     * @return array<string, mixed>
     */
    private function normalizeCoverageSummary(?array $coverageSummary): array
    {
        return [
            'lineItemsExcluded' => max((int) ($coverageSummary['lineItemsExcluded'] ?? 0), 0),
            'lineItemsManualReview' => max((int) ($coverageSummary['lineItemsManualReview'] ?? 0), 0),
            'lineItemsCoveredWithRule' => max((int) ($coverageSummary['lineItemsCoveredWithRule'] ?? 0), 0),
            'lineItemsUsingPolicyRule' => max((int) ($coverageSummary['lineItemsUsingPolicyRule'] ?? 0), 0),
            'matchedRuleCount' => max((int) ($coverageSummary['matchedRuleCount'] ?? 0), 0),
            'matchedRuleCodes' => array_values(array_filter(
                array_map(
                    fn (mixed $value): string => trim((string) $value),
                    is_array($coverageSummary['matchedRuleCodes'] ?? null)
                        ? $coverageSummary['matchedRuleCodes']
                        : [],
                ),
                static fn (string $value): bool => $value !== '',
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function selfPayPayerSummary(float $totalAmount, string $currencyCode): array
    {
        return [
            'settlementPath' => 'self_pay',
            'payerType' => 'self_pay',
            'payerName' => 'Self-pay',
            'contractId' => null,
            'contractCode' => null,
            'contractName' => null,
            'currencyCode' => $currencyCode,
            'coveragePercent' => 0.0,
            'coveredAmountByPercent' => 0.0,
            'copayType' => 'none',
            'copayValue' => 0.0,
            'copayAmount' => 0.0,
            'expectedPayerAmount' => 0.0,
            'expectedPatientAmount' => $totalAmount,
            'requiresPreAuthorization' => false,
            'claimSubmissionDeadlineDays' => null,
            'claimSubmissionDueAt' => null,
            'settlementCycleDays' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payerContract
     * @return array<string, mixed>
     */
    private function contractPayerSummary(
        array $payerContract,
        float $totalAmount,
        string $currencyCode,
        CarbonImmutable $invoiceDate,
    ): array {
        $coveragePercent = round(min(max((float) ($payerContract['default_coverage_percent'] ?? 0), 0), 100), 2);
        $coveredAmountByPercent = round($totalAmount * ($coveragePercent / 100), 2);
        $copayType = $this->normalizeCopayType($payerContract['default_copay_type'] ?? null);
        $copayValue = round(max((float) ($payerContract['default_copay_value'] ?? 0), 0), 2);
        $copayAmount = $this->copayAmount(
            totalAmount: $totalAmount,
            copayType: $copayType,
            copayValue: $copayValue,
        );
        $patientShareFromCoverage = round(max($totalAmount - $coveredAmountByPercent, 0), 2);
        $expectedPatientAmount = round(min($totalAmount, max($patientShareFromCoverage, $copayAmount)), 2);
        $expectedPayerAmount = round(max($totalAmount - $expectedPatientAmount, 0), 2);
        $claimSubmissionDeadlineDays = $this->normalizeNullablePositiveInteger(
            $payerContract['claim_submission_deadline_days'] ?? null,
        );

        return [
            'settlementPath' => 'payer_contract',
            'payerType' => $this->normalizeNullableString($payerContract['payer_type'] ?? null) ?? 'other',
            'payerName' => $this->normalizeNullableString($payerContract['payer_name'] ?? null),
            'payerPlanName' => $this->normalizeNullableString($payerContract['payer_plan_name'] ?? null),
            'contractId' => $payerContract['id'] ?? null,
            'contractCode' => $this->normalizeNullableString($payerContract['contract_code'] ?? null),
            'contractName' => $this->normalizeNullableString($payerContract['contract_name'] ?? null),
            'currencyCode' => $currencyCode,
            'coveragePercent' => $coveragePercent,
            'coveredAmountByPercent' => $coveredAmountByPercent,
            'copayType' => $copayType,
            'copayValue' => $copayValue,
            'copayAmount' => $copayAmount,
            'expectedPayerAmount' => $expectedPayerAmount,
            'expectedPatientAmount' => $expectedPatientAmount,
            'requiresPreAuthorization' => (bool) ($payerContract['requires_pre_authorization'] ?? false),
            'claimSubmissionDeadlineDays' => $claimSubmissionDeadlineDays,
            'claimSubmissionDueAt' => $claimSubmissionDeadlineDays !== null
                ? $invoiceDate->addDays($claimSubmissionDeadlineDays)->toISOString()
                : null,
            'settlementCycleDays' => $this->normalizeNullablePositiveInteger(
                $payerContract['settlement_cycle_days'] ?? null,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payerSummary
     * @param  array<string, mixed>  $authorizationSummary
     * @param  array<string, mixed>  $coverageSummary
     * @return array<string, mixed>
     */
    private function claimReadiness(array $payerSummary, array $authorizationSummary, array $coverageSummary): array
    {
        $claimEligible = ($payerSummary['payerType'] ?? 'self_pay') !== 'self_pay'
            && ((float) ($payerSummary['expectedPayerAmount'] ?? 0)) > 0;
        $requiresContractPreAuthorization = (bool) ($payerSummary['requiresPreAuthorization'] ?? false);
        $lineItemsRequiringAuthorization = max((int) ($authorizationSummary['lineItemsRequiringAuthorization'] ?? 0), 0);
        $lineItemsAutoApproved = max((int) ($authorizationSummary['lineItemsAutoApproved'] ?? 0), 0);
        $requiresManualAuthorization = $lineItemsRequiringAuthorization > $lineItemsAutoApproved;
        $lineItemsExcluded = max((int) ($coverageSummary['lineItemsExcluded'] ?? 0), 0);
        $lineItemsManualReview = max((int) ($coverageSummary['lineItemsManualReview'] ?? 0), 0);
        $lineItemsUsingPolicyRule = max((int) ($coverageSummary['lineItemsUsingPolicyRule'] ?? 0), 0);

        $state = 'ready';
        $ready = true;
        $blockingReasons = [];
        $guidance = [];

        if (! $claimEligible) {
            $state = 'not_applicable';
            $ready = false;
            $guidance[] = 'No payer-sponsored balance is expected on this invoice.';
        } elseif ($lineItemsManualReview > 0) {
            $state = 'coverage_review_required';
            $ready = false;
            $blockingReasons[] = sprintf(
                '%d line item%s require manual payer coverage review before claim submission.',
                $lineItemsManualReview,
                $lineItemsManualReview === 1 ? '' : 's',
            );
        } elseif ($lineItemsExcluded > 0) {
            $state = 'coverage_exception';
            $ready = false;
            $blockingReasons[] = sprintf(
                '%d line item%s are excluded by contract policy and must be split to patient-share follow-up before claim submission.',
                $lineItemsExcluded,
                $lineItemsExcluded === 1 ? '' : 's',
            );
        } elseif ($requiresContractPreAuthorization) {
            $state = 'preauthorization_required';
            $ready = false;
            $blockingReasons[] = 'Selected payer contract requires pre-authorization review before claim submission.';
        } elseif ($requiresManualAuthorization) {
            $state = 'authorization_required';
            $ready = false;
            $blockingReasons[] = 'One or more line items still require payer authorization before claim submission.';
        } else {
            $guidance[] = 'Invoice can proceed to claims workflow once issued.';
        }

        if (($payerSummary['claimSubmissionDueAt'] ?? null) !== null) {
            $guidance[] = sprintf(
                'Submit claim before %s to stay inside the contract deadline.',
                (string) $payerSummary['claimSubmissionDueAt'],
            );
        }

        if ($lineItemsUsingPolicyRule > 0) {
            $guidance[] = sprintf(
                'Contract policy rules are controlling %d line item%s on this invoice.',
                $lineItemsUsingPolicyRule,
                $lineItemsUsingPolicyRule === 1 ? '' : 's',
            );
        }

        return [
            'claimEligible' => $claimEligible,
            'ready' => $ready,
            'state' => $state,
            'blockingReasons' => $blockingReasons,
            'guidance' => $guidance,
            'requiresPreAuthorization' => $requiresContractPreAuthorization,
            'requiresManualAuthorization' => $requiresManualAuthorization,
            'authorizationSummary' => $authorizationSummary,
            'coverageSummary' => $coverageSummary,
            'expectedClaimAmount' => round(max((float) ($payerSummary['expectedPayerAmount'] ?? 0), 0), 2),
            'claimSubmissionDueAt' => $payerSummary['claimSubmissionDueAt'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $authorizationSummary
     * @param  array<string, mixed>  $coverageSummary
     * @return array<string, mixed>
     */
    private function selfPayClaimReadiness(float $totalAmount, array $authorizationSummary, array $coverageSummary): array
    {
        return [
            'claimEligible' => false,
            'ready' => false,
            'state' => 'not_applicable',
            'blockingReasons' => [],
            'guidance' => ['Treat this invoice as self-pay / direct collection.'],
            'requiresPreAuthorization' => false,
            'requiresManualAuthorization' => false,
            'authorizationSummary' => $authorizationSummary,
            'coverageSummary' => $coverageSummary,
            'expectedClaimAmount' => 0.0,
            'claimSubmissionDueAt' => null,
            'selfPayExposure' => $totalAmount,
        ];
    }

    /**
     * @param  array<string, mixed>  $payerContract
     */
    private function assertCurrencyAlignment(array $payerContract, string $currencyCode): void
    {
        $contractCurrencyCode = strtoupper(trim((string) ($payerContract['currency_code'] ?? '')));
        if ($contractCurrencyCode === '') {
            return;
        }

        if ($contractCurrencyCode !== $currencyCode) {
            throw new BillingInvoicePricingResolutionException(
                'billingPayerContractId',
                sprintf(
                    'Selected billing payer contract is priced in %s and cannot be used for a %s invoice.',
                    $contractCurrencyCode,
                    $currencyCode,
                ),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payerContract
     */
    private function assertEffectiveOnInvoiceDate(array $payerContract, CarbonImmutable $invoiceDate): void
    {
        $effectiveFrom = $this->normalizeDateTime($payerContract['effective_from'] ?? null);
        if ($effectiveFrom !== null && $effectiveFrom->greaterThan($invoiceDate)) {
            throw new BillingInvoicePricingResolutionException(
                'billingPayerContractId',
                'Selected billing payer contract is not yet effective on the invoice date.',
            );
        }

        $effectiveTo = $this->normalizeDateTime($payerContract['effective_to'] ?? null);
        if ($effectiveTo !== null && $effectiveTo->lessThan($invoiceDate)) {
            throw new BillingInvoicePricingResolutionException(
                'billingPayerContractId',
                'Selected billing payer contract is no longer effective on the invoice date.',
            );
        }
    }

    private function normalizeInvoiceDate(?string $value): CarbonImmutable
    {
        $normalizedValue = $this->normalizeNullableString($value);

        return $normalizedValue !== null
            ? CarbonImmutable::parse($normalizedValue)
            : CarbonImmutable::now();
    }

    private function normalizeDateTime(mixed $value): ?CarbonImmutable
    {
        $normalizedValue = $this->normalizeNullableString($value);

        return $normalizedValue !== null ? CarbonImmutable::parse($normalizedValue) : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeCopayType(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['fixed', 'percentage', 'none'], true)
            ? $normalized
            : 'none';
    }

    private function normalizeNullablePositiveInteger(mixed $value): ?int
    {
        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    private function copayAmount(float $totalAmount, string $copayType, float $copayValue): float
    {
        if ($copayType === 'fixed') {
            return round(min($totalAmount, $copayValue), 2);
        }

        if ($copayType === 'percentage') {
            return round(min($totalAmount, $totalAmount * ($copayValue / 100)), 2);
        }

        return 0.0;
    }
}