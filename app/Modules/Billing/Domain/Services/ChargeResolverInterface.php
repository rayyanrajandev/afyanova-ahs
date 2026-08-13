<?php

namespace App\Modules\Billing\Domain\Services;

/**
 * PricingEngine_Technical_Design.md §2. Single entry point for resolving a
 * price by chargeable-item identity (a real FK) instead of string-matching a
 * service code -- the thing the whole pricing engine initiative exists to
 * fix. Phase 2: built and unit-tested, but only ever called from the shadow
 * comparison path (App\Jobs\ShadowDiffChargeCaptureCandidateJob). Nothing
 * user-facing consumes this yet -- that starts in Phase 3, domain by domain.
 */
interface ChargeResolverInterface
{
    /**
     * @return array{
     *     chargeableItemId: string,
     *     unitPrice: float,
     *     quantity: float,
     *     lineTotal: float,
     *     currencyCode: string,
     *     pricingStatus: string,
     * }
     */
    public function resolvePrice(
        string $chargeableItemId,
        float $quantityOrDuration,
        ?string $asOfDate,
        ?string $tenantId,
        ?string $facilityId,
        ?string $payerContractId,
        string $currencyCode,
    ): array;
}
