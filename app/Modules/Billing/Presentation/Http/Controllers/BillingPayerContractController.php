<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\Exceptions\DuplicateBillingPayerAuthorizationRuleCodeException;
use App\Modules\Billing\Application\Exceptions\DuplicateBillingPayerContractCodeException;
use App\Modules\Billing\Application\Exceptions\OverlappingBillingPayerContractPriceOverrideException;
use App\Modules\Billing\Application\Support\ChargeableItemPricingLookup;
use App\Modules\Billing\Application\UseCases\CreateBillingPayerAuthorizationRuleUseCase;
use App\Modules\Billing\Application\UseCases\CreateBillingPayerContractUseCase;
use App\Modules\Billing\Application\UseCases\CreateBillingPayerContractPriceOverrideUseCase;
use App\Modules\Billing\Application\UseCases\GetBillingPayerContractPolicySummaryUseCase;
use App\Modules\Billing\Application\UseCases\GetBillingPayerContractUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingPayerAuthorizationRuleAuditLogsUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingPayerAuthorizationRulesUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingPayerContractAuditLogsUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingPayerContractPriceOverrideAuditLogsUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingPayerContractPriceOverridesUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingPayerContractsUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingPayerContractStatusCountsUseCase;
use App\Modules\Billing\Application\UseCases\UpdateBillingPayerAuthorizationRuleStatusUseCase;
use App\Modules\Billing\Application\UseCases\UpdateBillingPayerAuthorizationRuleUseCase;
use App\Modules\Billing\Application\UseCases\UpdateBillingPayerContractStatusUseCase;
use App\Modules\Billing\Application\UseCases\UpdateBillingPayerContractPriceOverrideStatusUseCase;
use App\Modules\Billing\Application\UseCases\UpdateBillingPayerContractPriceOverrideUseCase;
use App\Modules\Billing\Application\UseCases\UpdateBillingPayerContractUseCase;
use App\Modules\Billing\Presentation\Http\Requests\StoreBillingPayerAuthorizationRuleRequest;
use App\Modules\Billing\Presentation\Http\Requests\StoreBillingPayerContractRequest;
use App\Modules\Billing\Presentation\Http\Requests\StoreBillingPayerContractPriceOverrideRequest;
use App\Modules\Billing\Presentation\Http\Requests\UpdateBillingPayerAuthorizationRuleRequest;
use App\Modules\Billing\Presentation\Http\Requests\UpdateBillingPayerAuthorizationRuleStatusRequest;
use App\Modules\Billing\Presentation\Http\Requests\UpdateBillingPayerContractRequest;
use App\Modules\Billing\Presentation\Http\Requests\UpdateBillingPayerContractPriceOverrideRequest;
use App\Modules\Billing\Presentation\Http\Requests\UpdateBillingPayerContractPriceOverrideStatusRequest;
use App\Modules\Billing\Presentation\Http\Requests\UpdateBillingPayerContractStatusRequest;
use App\Modules\Billing\Presentation\Http\Transformers\BillingPayerAuthorizationRuleAuditLogResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingPayerAuthorizationRuleResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingPayerContractAuditLogResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingPayerContractPriceOverrideAuditLogResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingPayerContractPriceOverrideResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingPayerContractResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingPayerContractController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListBillingPayerContractsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([BillingPayerContractResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListBillingPayerContractStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function store(
        StoreBillingPayerContractRequest $request,
        CreateBillingPayerContractUseCase $useCase
    ): JsonResponse {
        try {
            $contract = $useCase->execute(
                payload: $this->toContractPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateBillingPayerContractCodeException $exception) {
            return $this->validationError('contractCode', $exception->getMessage());
        }

        return response()->json([
            'data' => BillingPayerContractResponseTransformer::transform($contract),
        ], 201);
    }

    public function show(string $id, GetBillingPayerContractUseCase $useCase): JsonResponse
    {
        $contract = $useCase->execute($id);
        abort_if($contract === null, 404, 'Billing payer contract not found.');

        return response()->json([
            'data' => BillingPayerContractResponseTransformer::transform($contract),
        ]);
    }

    public function update(
        string $id,
        UpdateBillingPayerContractRequest $request,
        UpdateBillingPayerContractUseCase $useCase
    ): JsonResponse {
        try {
            $contract = $useCase->execute(
                id: $id,
                payload: $this->toContractPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateBillingPayerContractCodeException $exception) {
            return $this->validationError('contractCode', $exception->getMessage());
        }

        abort_if($contract === null, 404, 'Billing payer contract not found.');

        return response()->json([
            'data' => BillingPayerContractResponseTransformer::transform($contract),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateBillingPayerContractStatusRequest $request,
        UpdateBillingPayerContractStatusUseCase $useCase
    ): JsonResponse {
        try {
            $contract = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($contract === null, 404, 'Billing payer contract not found.');

        return response()->json([
            'data' => BillingPayerContractResponseTransformer::transform($contract),
        ]);
    }

    public function auditLogs(
        string $id,
        Request $request,
        ListBillingPayerContractAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(billingPayerContractId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Billing payer contract not found.');

        return response()->json([
            'data' => array_map([BillingPayerContractAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListBillingPayerContractAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            billingPayerContractId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Billing payer contract not found.');

        $safeId = $this->safeExportIdentifier($id, 'billing-payer-contract');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('billing_payer_contract_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    billingPayerContractId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }

    public function authorizationRules(
        string $id,
        Request $request,
        ListBillingPayerAuthorizationRulesUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($id, $request->all());
        abort_if($result === null, 404, 'Billing payer contract not found.');

        return response()->json([
            'data' => array_map([BillingPayerAuthorizationRuleResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function authorizationRuleSummary(
        string $id,
        GetBillingPayerContractPolicySummaryUseCase $useCase,
    ): JsonResponse {
        $result = $useCase->execute($id);
        abort_if($result === null, 404, 'Billing payer contract not found.');

        return response()->json([
            'data' => $result,
        ]);
    }

    public function priceOverrides(
        string $id,
        Request $request,
        ListBillingPayerContractPriceOverridesUseCase $useCase,
        ChargeableItemPricingLookup $chargeableItemPricingLookup,
        \App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface $platformScopeContext,
    ): JsonResponse {
        $result = $useCase->execute($id, $request->all());
        abort_if($result === null, 404, 'Billing payer contract not found.');

        return response()->json([
            'data' => array_map(
                fn (array $override): array => $this->transformPriceOverride($override, $chargeableItemPricingLookup, $platformScopeContext),
                $result['data'],
            ),
            'meta' => $result['meta'],
        ]);
    }

    public function storePriceOverride(
        string $id,
        StoreBillingPayerContractPriceOverrideRequest $request,
        CreateBillingPayerContractPriceOverrideUseCase $useCase,
        ChargeableItemPricingLookup $chargeableItemPricingLookup,
        \App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface $platformScopeContext,
    ): JsonResponse {
        try {
            $override = $useCase->execute(
                billingPayerContractId: $id,
                payload: $this->toPriceOverridePersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (OverlappingBillingPayerContractPriceOverrideException $exception) {
            return $this->validationError('effectiveFrom', $exception->getMessage());
        }

        abort_if($override === null, 404, 'Billing payer contract not found.');

        return response()->json([
            'data' => $this->transformPriceOverride($override, $chargeableItemPricingLookup, $platformScopeContext),
        ], 201);
    }

    public function updatePriceOverride(
        string $id,
        string $overrideId,
        UpdateBillingPayerContractPriceOverrideRequest $request,
        UpdateBillingPayerContractPriceOverrideUseCase $useCase,
        ChargeableItemPricingLookup $chargeableItemPricingLookup,
        \App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface $platformScopeContext,
    ): JsonResponse {
        try {
            $override = $useCase->execute(
                billingPayerContractId: $id,
                id: $overrideId,
                payload: $this->toPriceOverridePersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (OverlappingBillingPayerContractPriceOverrideException $exception) {
            return $this->validationError('effectiveFrom', $exception->getMessage());
        }

        abort_if($override === null, 404, 'Billing payer contract price override not found.');

        return response()->json([
            'data' => $this->transformPriceOverride($override, $chargeableItemPricingLookup, $platformScopeContext),
        ]);
    }

    public function updatePriceOverrideStatus(
        string $id,
        string $overrideId,
        UpdateBillingPayerContractPriceOverrideStatusRequest $request,
        UpdateBillingPayerContractPriceOverrideStatusUseCase $useCase,
        ChargeableItemPricingLookup $chargeableItemPricingLookup,
        \App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface $platformScopeContext,
    ): JsonResponse {
        try {
            $override = $useCase->execute(
                billingPayerContractId: $id,
                id: $overrideId,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($override === null, 404, 'Billing payer contract price override not found.');

        return response()->json([
            'data' => $this->transformPriceOverride($override, $chargeableItemPricingLookup, $platformScopeContext),
        ]);
    }

    public function priceOverrideAuditLogs(
        string $id,
        string $overrideId,
        Request $request,
        ListBillingPayerContractPriceOverrideAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(
            billingPayerContractId: $id,
            billingPayerContractPriceOverrideId: $overrideId,
            filters: $request->all(),
        );
        abort_if($result === null, 404, 'Billing payer contract price override not found.');

        return response()->json([
            'data' => array_map([BillingPayerContractPriceOverrideAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportPriceOverrideAuditLogsCsv(
        string $id,
        string $overrideId,
        Request $request,
        ListBillingPayerContractPriceOverrideAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            billingPayerContractId: $id,
            billingPayerContractPriceOverrideId: $overrideId,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Billing payer contract price override not found.');

        $safeOverrideId = $this->safeExportIdentifier($overrideId, 'billing-payer-contract-price-override');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('billing_payer_contract_price_override_audit_%s_%s', $safeOverrideId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $overrideId, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    billingPayerContractId: $id,
                    billingPayerContractPriceOverrideId: $overrideId,
                    filters: $pageFilters,
                );
            },
        );
    }

    public function storeAuthorizationRule(
        string $id,
        StoreBillingPayerAuthorizationRuleRequest $request,
        CreateBillingPayerAuthorizationRuleUseCase $useCase
    ): JsonResponse {
        try {
            $rule = $useCase->execute(
                billingPayerContractId: $id,
                payload: $this->toAuthorizationRulePersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateBillingPayerAuthorizationRuleCodeException $exception) {
            return $this->validationError('ruleCode', $exception->getMessage());
        }

        abort_if($rule === null, 404, 'Billing payer contract not found.');

        return response()->json([
            'data' => BillingPayerAuthorizationRuleResponseTransformer::transform($rule),
        ], 201);
    }

    public function updateAuthorizationRule(
        string $id,
        string $ruleId,
        UpdateBillingPayerAuthorizationRuleRequest $request,
        UpdateBillingPayerAuthorizationRuleUseCase $useCase
    ): JsonResponse {
        try {
            $rule = $useCase->execute(
                billingPayerContractId: $id,
                id: $ruleId,
                payload: $this->toAuthorizationRulePersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateBillingPayerAuthorizationRuleCodeException $exception) {
            return $this->validationError('ruleCode', $exception->getMessage());
        }

        abort_if($rule === null, 404, 'Billing payer authorization rule not found.');

        return response()->json([
            'data' => BillingPayerAuthorizationRuleResponseTransformer::transform($rule),
        ]);
    }

    public function updateAuthorizationRuleStatus(
        string $id,
        string $ruleId,
        UpdateBillingPayerAuthorizationRuleStatusRequest $request,
        UpdateBillingPayerAuthorizationRuleStatusUseCase $useCase
    ): JsonResponse {
        try {
            $rule = $useCase->execute(
                billingPayerContractId: $id,
                id: $ruleId,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($rule === null, 404, 'Billing payer authorization rule not found.');

        return response()->json([
            'data' => BillingPayerAuthorizationRuleResponseTransformer::transform($rule),
        ]);
    }

    public function authorizationRuleAuditLogs(
        string $id,
        string $ruleId,
        Request $request,
        ListBillingPayerAuthorizationRuleAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(
            billingPayerContractId: $id,
            billingPayerAuthorizationRuleId: $ruleId,
            filters: $request->all(),
        );
        abort_if($result === null, 404, 'Billing payer authorization rule not found.');

        return response()->json([
            'data' => array_map([BillingPayerAuthorizationRuleAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuthorizationRuleAuditLogsCsv(
        string $id,
        string $ruleId,
        Request $request,
        ListBillingPayerAuthorizationRuleAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            billingPayerContractId: $id,
            billingPayerAuthorizationRuleId: $ruleId,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Billing payer authorization rule not found.');

        $safeRuleId = $this->safeExportIdentifier($ruleId, 'billing-payer-authorization-rule');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('billing_payer_authorization_rule_audit_%s_%s', $safeRuleId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $ruleId, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    billingPayerContractId: $id,
                    billingPayerAuthorizationRuleId: $ruleId,
                    filters: $pageFilters,
                );
            },
        );
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function transformPriceOverride(
        array $override,
        ChargeableItemPricingLookup $chargeableItemPricingLookup,
        \App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface $platformScopeContext,
    ): array {
        return BillingPayerContractPriceOverrideResponseTransformer::transform(
            $this->withPriceOverridePricingImpact($override, $chargeableItemPricingLookup, $platformScopeContext),
        );
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function withPriceOverridePricingImpact(
        array $override,
        ChargeableItemPricingLookup $chargeableItemPricingLookup,
        \App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface $platformScopeContext,
    ): array {
        $tenantId = $platformScopeContext->tenantId();
        $facilityId = $platformScopeContext->facilityId();

        $catalogItem = null;
        $linkedChargeableItemId = trim((string) ($override['chargeable_item_id'] ?? ''));
        $currencyCode = strtoupper(trim((string) ($override['currency_code'] ?? 'TZS')));
        $asOfDateTime = $this->normalizeNullableDateTime($override['effective_from'] ?? null) ?? now()->toDateTimeString();

        if ($linkedChargeableItemId !== '') {
            $catalogItem = $chargeableItemPricingLookup->findById(
                chargeableItemId: $linkedChargeableItemId,
                currencyCode: $currencyCode,
                asOfDateTime: $asOfDateTime,
                tenantId: $tenantId,
                facilityId: $facilityId,
            );
        }

        if ($catalogItem === null) {
            $serviceCode = strtoupper(trim((string) ($override['service_code'] ?? '')));
            if ($serviceCode !== '') {
                $catalogItem = $chargeableItemPricingLookup->findByServiceCode(
                    serviceCode: $serviceCode,
                    currencyCode: $currencyCode,
                    asOfDateTime: $asOfDateTime,
                    tenantId: $tenantId,
                    facilityId: $facilityId,
                );
            }
        }

        $basePrice = $this->normalizeNullableMoney($catalogItem['base_price'] ?? null);
        $overrideValue = $this->normalizeNullableMoney($override['override_value'] ?? null);
        $resolvedNegotiatedPrice = $this->resolveNegotiatedPrice(
            pricingStrategy: (string) ($override['pricing_strategy'] ?? ''),
            overrideValue: $overrideValue,
            basePrice: $basePrice,
        );
        $varianceAmount = $this->calculateVarianceAmount($resolvedNegotiatedPrice, $basePrice);
        $variancePercent = $this->calculateVariancePercent($varianceAmount, $basePrice);

        $override['catalog_pricing_status'] = $basePrice !== null ? 'matched_active_service_price' : 'missing_active_service_price';
        $override['catalog_base_price'] = $basePrice;
        $override['catalog_currency_code'] = $catalogItem['currency_code'] ?? ($override['currency_code'] ?? null);
        $override['resolved_negotiated_price'] = $resolvedNegotiatedPrice;
        $override['variance_amount'] = $varianceAmount;
        $override['variance_percent'] = $variancePercent;
        $override['variance_direction'] = $this->varianceDirection($varianceAmount);

        return $override;
    }

    private function resolveNegotiatedPrice(string $pricingStrategy, ?string $overrideValue, ?string $basePrice): ?string
    {
        $normalizedStrategy = strtolower(trim($pricingStrategy));
        $overrideAmount = $this->toFloatOrNull($overrideValue);
        $baseAmount = $this->toFloatOrNull($basePrice);

        if ($overrideAmount === null) {
            return null;
        }

        if ($normalizedStrategy === 'fixed_price') {
            return $this->formatMoneyValue($overrideAmount);
        }

        if ($baseAmount === null) {
            return null;
        }

        if ($normalizedStrategy === 'discount_percent') {
            return $this->formatMoneyValue($baseAmount * (1 - ($overrideAmount / 100)));
        }

        if ($normalizedStrategy === 'markup_percent') {
            return $this->formatMoneyValue($baseAmount * (1 + ($overrideAmount / 100)));
        }

        return null;
    }

    private function calculateVarianceAmount(?string $resolvedNegotiatedPrice, ?string $basePrice): ?string
    {
        $resolvedAmount = $this->toFloatOrNull($resolvedNegotiatedPrice);
        $baseAmount = $this->toFloatOrNull($basePrice);
        if ($resolvedAmount === null || $baseAmount === null) {
            return null;
        }

        return $this->formatMoneyValue($resolvedAmount - $baseAmount);
    }

    private function calculateVariancePercent(?string $varianceAmount, ?string $basePrice): ?string
    {
        $variance = $this->toFloatOrNull($varianceAmount);
        $baseAmount = $this->toFloatOrNull($basePrice);
        if ($variance === null || $baseAmount === null || abs($baseAmount) < 0.00001) {
            return null;
        }

        return number_format(($variance / $baseAmount) * 100, 2, '.', '');
    }

    private function varianceDirection(?string $varianceAmount): ?string
    {
        $variance = $this->toFloatOrNull($varianceAmount);
        if ($variance === null) {
            return null;
        }
        if ($variance > 0) {
            return 'markup';
        }
        if ($variance < 0) {
            return 'discount';
        }

        return 'same';
    }

    private function normalizeNullableMoney(mixed $value): ?string
    {
        $numeric = $this->toFloatOrNull($value);

        return $numeric === null ? null : $this->formatMoneyValue($numeric);
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private function formatMoneyValue(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function normalizeNullableDateTime(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function validationError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => 'VALIDATION_ERROR',
            'errors' => [
                $field => [$message],
            ],
        ], 422);
    }

    private function tenantScopeRequiredError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
    }

    private function toContractPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'contractCode' => 'contract_code',
            'contractName' => 'contract_name',
            'payerType' => 'payer_type',
            'payerName' => 'payer_name',
            'payerPlanCode' => 'payer_plan_code',
            'payerPlanName' => 'payer_plan_name',
            'currencyCode' => 'currency_code',
            'defaultCoveragePercent' => 'default_coverage_percent',
            'defaultCopayType' => 'default_copay_type',
            'defaultCopayValue' => 'default_copay_value',
            'requiresPreAuthorization' => 'requires_pre_authorization',
            'claimSubmissionDeadlineDays' => 'claim_submission_deadline_days',
            'settlementCycleDays' => 'settlement_cycle_days',
            'effectiveFrom' => 'effective_from',
            'effectiveTo' => 'effective_to',
            'termsAndNotes' => 'terms_and_notes',
            'metadata' => 'metadata',
        ];

        $payload = [];
        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        return $payload;
    }

    private function toAuthorizationRulePersistencePayload(array $validated): array
    {
        $fieldMap = [
            'billingServiceCatalogItemId' => 'billing_service_catalog_item_id',
            'ruleCode' => 'rule_code',
            'ruleName' => 'rule_name',
            'serviceCode' => 'service_code',
            'serviceType' => 'service_type',
            'department' => 'department',
            'diagnosisCode' => 'diagnosis_code',
            'priority' => 'priority',
            'minPatientAgeYears' => 'min_patient_age_years',
            'maxPatientAgeYears' => 'max_patient_age_years',
            'gender' => 'gender',
            'amountThreshold' => 'amount_threshold',
            'quantityLimit' => 'quantity_limit',
            'coverageDecision' => 'coverage_decision',
            'coveragePercentOverride' => 'coverage_percent_override',
            'copayType' => 'copay_type',
            'copayValue' => 'copay_value',
            'benefitLimitAmount' => 'benefit_limit_amount',
            'effectiveFrom' => 'effective_from',
            'effectiveTo' => 'effective_to',
            'requiresAuthorization' => 'requires_authorization',
            'autoApprove' => 'auto_approve',
            'authorizationValidityDays' => 'authorization_validity_days',
            'ruleNotes' => 'rule_notes',
            'ruleExpression' => 'rule_expression',
            'metadata' => 'metadata',
        ];

        $payload = [];
        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        return $payload;
    }

    private function toPriceOverridePersistencePayload(array $validated): array
    {
        $fieldMap = [
            'billingServiceCatalogItemId' => 'billing_service_catalog_item_id',
            'chargeableItemId' => 'chargeable_item_id',
            'serviceCode' => 'service_code',
            'serviceName' => 'service_name',
            'serviceType' => 'service_type',
            'department' => 'department',
            'pricingStrategy' => 'pricing_strategy',
            'overrideValue' => 'override_value',
            'effectiveFrom' => 'effective_from',
            'effectiveTo' => 'effective_to',
            'overrideNotes' => 'override_notes',
            'metadata' => 'metadata',
        ];

        $payload = [];
        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        return $payload;
    }
}
