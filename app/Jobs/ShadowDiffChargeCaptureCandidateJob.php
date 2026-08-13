<?php

namespace App\Jobs;

use App\Modules\Billing\Domain\Services\ChargeResolverInterface;
use App\Modules\Billing\Infrastructure\Models\PricingEngineShadowDiffModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * PricingEngine_Migration_Plan.md Phase 2. Compares the legacy string-matched
 * price (already computed and returned to the user by
 * ListBillingChargeCaptureCandidatesUseCase) against what the new
 * chargeable_item_id-based resolver would produce for the same event --
 * without ever affecting what the user actually sees. Dispatched, never run
 * inline, so a slow/failed comparison can't add latency or risk to the real
 * charge-capture-candidates response.
 */
class ShadowDiffChargeCaptureCandidateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $sourceKind,
        private readonly string $sourceId,
        private readonly ?string $chargeableItemId,
        private readonly float $quantityOrDuration,
        private readonly ?string $performedAt,
        private readonly ?string $tenantId,
        private readonly ?string $facilityId,
        private readonly ?string $payerContractId,
        private readonly string $legacyCurrencyCode,
        private readonly ?string $legacyServiceCode,
        private readonly float $legacyUnitPrice,
        private readonly string $legacyPricingStatus,
    ) {}

    public function handle(ChargeResolverInterface $chargeResolver): void
    {
        // Nothing to compare against yet -- this order's Phase 3 domain
        // migration hasn't happened, and it has no legacy catalog FK to
        // fall back on either (e.g. consultations, bed-days). Not an error.
        if ($this->chargeableItemId === null) {
            return;
        }

        $new = $chargeResolver->resolvePrice(
            chargeableItemId: $this->chargeableItemId,
            quantityOrDuration: $this->quantityOrDuration,
            asOfDate: $this->performedAt,
            tenantId: $this->tenantId,
            facilityId: $this->facilityId,
            payerContractId: $this->payerContractId,
            currencyCode: $this->legacyCurrencyCode,
        );

        [$matched, $reason] = $this->compare($new);

        PricingEngineShadowDiffModel::query()->create([
            'source_kind' => $this->sourceKind,
            'source_id' => $this->sourceId,
            'chargeable_item_id' => $this->chargeableItemId,
            'legacy_service_code' => $this->legacyServiceCode,
            'legacy_unit_price' => $this->legacyUnitPrice,
            'legacy_currency_code' => $this->legacyCurrencyCode,
            'legacy_pricing_status' => $this->legacyPricingStatus,
            'new_unit_price' => $new['unitPrice'],
            'new_currency_code' => $new['currencyCode'],
            'new_pricing_status' => $new['pricingStatus'],
            'matched' => $matched,
            'mismatch_reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array{unitPrice: float, currencyCode: string, pricingStatus: string}  $new
     * @return array{0: bool, 1: ?string}
     */
    private function compare(array $new): array
    {
        // Currency is an input to resolvePrice(), not an independently
        // resolved output, so this only catches the legacy candidate's
        // currency code being un-normalized (wrong case/whitespace) --
        // a legacy data-hygiene signal, not a genuine pricing disagreement.
        if ($this->legacyCurrencyCode !== $new['currencyCode']) {
            return [false, 'currency_differs'];
        }

        $legacyPriced = $this->legacyPricingStatus === 'priced';
        $newPriced = $new['pricingStatus'] === 'priced';

        if ($legacyPriced && ! $newPriced) {
            return [false, 'legacy_priced_new_missing'];
        }

        if (! $legacyPriced && $newPriced) {
            return [false, 'legacy_missing_new_priced'];
        }

        if (! $legacyPriced && ! $newPriced) {
            return [true, null];
        }

        if (abs($this->legacyUnitPrice - $new['unitPrice']) > 0.01) {
            return [false, 'price_differs'];
        }

        return [true, null];
    }
}
