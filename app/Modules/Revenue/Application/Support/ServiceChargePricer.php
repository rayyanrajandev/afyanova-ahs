<?php

namespace App\Modules\Revenue\Application\Support;

use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Revenue\Domain\Services\ChargeResolverInterface;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\PricedCharge;
use App\Modules\Revenue\Infrastructure\Models\PriceBookEntryModel;

/**
 * Turns a chargeable item and a quantity into an exact, taxed, discounted
 * amount.
 *
 * Wraps ChargeResolver rather than replacing it: that class already answers
 * the hard question — which tariff applies, given payer, facility, tier and
 * date — and answers it payer-first with a fall back to the cash price. What
 * it does not do is tax, discount, or exact arithmetic, and it returns floats.
 * This is the boundary where floats stop: everything leaving here is Money.
 */
class ServiceChargePricer
{
    public function __construct(
        private readonly ChargeResolverInterface $chargeResolver,
    ) {}

    public function price(
        string $chargeableItemId,
        float $quantity,
        string $currencyCode,
        ?string $tenantId,
        ?string $facilityId,
        ?string $payerContractId = null,
        ?string $asOfDate = null,
        ?float $discountPercent = null,
        ?string $discountReason = null,
    ): PricedCharge {
        $resolved = $this->chargeResolver->resolvePrice(
            chargeableItemId: $chargeableItemId,
            quantityOrDuration: $quantity,
            asOfDate: $asOfDate,
            tenantId: $tenantId,
            facilityId: $facilityId,
            payerContractId: $payerContractId,
            currencyCode: $currencyCode,
        );

        $zero = Money::zero($currencyCode);
        $pricingStatus = (string) $resolved['pricingStatus'];

        if ($pricingStatus !== 'priced') {
            return new PricedCharge(
                chargeableItemId: $chargeableItemId,
                priceBookEntryId: null,
                unitPrice: $zero,
                quantity: (float) $resolved['quantity'],
                grossAmount: $zero,
                discountAmount: $zero,
                discountReason: null,
                taxAmount: $zero,
                netAmount: $zero,
                pricingStatus: $pricingStatus,
            );
        }

        $resolvedQuantity = (float) $resolved['quantity'];
        $unitPrice = Money::fromDecimal((string) $resolved['unitPrice'], $currencyCode);
        $gross = $unitPrice->multipliedBy($resolvedQuantity);

        $discount = $discountPercent !== null && $discountPercent > 0.0
            ? $gross->percentage(min($discountPercent, 100.0))
            : $zero;

        $taxable = $gross->minus($discount);
        $tax = $this->resolveTax($chargeableItemId, $taxable);

        return new PricedCharge(
            chargeableItemId: $chargeableItemId,
            priceBookEntryId: $this->resolvePriceBookEntryId(
                $chargeableItemId,
                $currencyCode,
                $tenantId,
                $facilityId,
                $payerContractId,
            ),
            unitPrice: $unitPrice,
            quantity: $resolvedQuantity,
            grossAmount: $gross,
            discountAmount: $discount,
            discountReason: $discount->isPositive() ? $discountReason : null,
            taxAmount: $tax,
            netAmount: $taxable->plus($tax),
            pricingStatus: 'priced',
        );
    }

    /**
     * Tax is read from the chargeable item, not the price book entry: an item
     * is taxable or it is not, whoever is paying and whatever tariff applies.
     */
    private function resolveTax(string $chargeableItemId, Money $taxableAmount): Money
    {
        $item = ChargeableItemModel::query()->find($chargeableItemId);

        if ($item === null || ! (bool) $item->is_taxable) {
            return Money::zero($taxableAmount->currencyCode);
        }

        $rate = (float) ($item->tax_rate_percent ?? 0);

        return $rate > 0.0 ? $taxableAmount->percentage($rate) : Money::zero($taxableAmount->currencyCode);
    }

    /**
     * Which tariff row the resolver actually used.
     *
     * ChargeResolver returns a price but not the row it came from, and the
     * charge needs the row so a historical price stays explainable after the
     * price book moves on. Re-running the same selection rules here would be a
     * second implementation that could drift, so this repeats only the
     * narrowing the resolver does and accepts null when it cannot be certain.
     */
    private function resolvePriceBookEntryId(
        string $chargeableItemId,
        string $currencyCode,
        ?string $tenantId,
        ?string $facilityId,
        ?string $payerContractId,
    ): ?string {
        $candidates = PriceBookEntryModel::query()
            ->where('chargeable_item_id', $chargeableItemId)
            ->where('currency_code', strtoupper($currencyCode))
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->where(fn ($q) => $q->whereNull('facility_id')->orWhere('facility_id', $facilityId))
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $payerMatched = $payerContractId !== null
            ? $candidates->where('payer_contract_id', $payerContractId)
            : $candidates->whereNull('payer_contract_id');

        $pool = $payerMatched->isNotEmpty() ? $payerMatched : $candidates->whereNull('payer_contract_id');
        if ($pool->isEmpty()) {
            return null;
        }

        $facilitySpecific = $pool->whereNotNull('facility_id');
        $pool = $facilitySpecific->isNotEmpty() ? $facilitySpecific : $pool;

        return (string) $pool
            ->sortByDesc(fn (PriceBookEntryModel $e): string => (string) ($e->effective_from ?? '0000-01-01'))
            ->first()
            ?->id;
    }
}
