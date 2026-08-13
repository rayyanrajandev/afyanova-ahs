<?php

namespace App\Modules\Billing\Application\Support;

use App\Modules\Billing\Domain\Services\ChargeResolverInterface;
use App\Modules\Billing\Infrastructure\Models\ConsultationMappingModel;

/**
 * PricingEngine_Migration_Plan.md Phase 5: Consultation is fully cut over
 * (first domain to have its legacy string-match path removed entirely) --
 * this is now the single, unconditional pricing path for consultation fees,
 * shared by the two places they get priced (AutoCaptureConsultationFeeUseCase
 * and ListBillingChargeCaptureCandidatesUseCase::consultationCandidates()).
 * Returns null when there's no explicit ConsultationMappingModel row for
 * this tier/department, or the mapping has no chargeable_item_id linked yet
 * -- both genuinely mean "not priced," not "fall back to something else."
 */
class ConsultationPricingResolver
{
    public function __construct(
        private readonly ChargeResolverInterface $chargeResolver,
    ) {}

    /**
     * @return array{chargeableItemId: string, unitPrice: float, quantity: float, lineTotal: float, currencyCode: string, pricingStatus: string}|null
     */
    public function resolveViaExplicitMapping(
        ?ConsultationMappingModel $mapping,
        string $tier,
        string $department,
        float $quantity,
        ?string $performedAt,
        ?string $tenantId,
        ?string $facilityId,
        string $currencyCode,
    ): ?array {
        $mapping ??= ConsultationMappingModel::query()
            ->where('clinician_tier', $tier)
            ->where('department', $department)
            ->first();

        if ($mapping === null || $mapping->chargeable_item_id === null) {
            return null;
        }

        return $this->chargeResolver->resolvePrice(
            chargeableItemId: $mapping->chargeable_item_id,
            quantityOrDuration: $quantity,
            asOfDate: $performedAt,
            tenantId: $tenantId,
            facilityId: $facilityId,
            payerContractId: null,
            currencyCode: $currencyCode,
        );
    }
}
