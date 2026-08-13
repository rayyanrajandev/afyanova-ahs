<?php

namespace App\Modules\Billing\Infrastructure\Services;

use App\Modules\Billing\Domain\Services\ChargeResolverInterface;
use App\Modules\Billing\Domain\Services\DurationChargeStrategy;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Support\CatalogGovernance\FacilityTierSupport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ChargeResolver implements ChargeResolverInterface
{
    public function __construct(
        private readonly FacilityTierSupport $facilityTierSupport,
        private readonly DurationChargeStrategy $durationChargeStrategy,
    ) {}

    public function resolvePrice(
        string $chargeableItemId,
        float $quantityOrDuration,
        ?string $asOfDate,
        ?string $tenantId,
        ?string $facilityId,
        ?string $payerContractId,
        string $currencyCode,
    ): array {
        $currencyCode = strtoupper(trim($currencyCode));
        $chargeableItem = ChargeableItemModel::query()->find($chargeableItemId);

        if ($chargeableItem === null) {
            return [
                'chargeableItemId' => $chargeableItemId,
                'unitPrice' => 0.0,
                'quantity' => max(0.0, $quantityOrDuration),
                'lineTotal' => 0.0,
                'currencyCode' => $currencyCode,
                'pricingStatus' => 'missing_chargeable_item',
            ];
        }

        $quantity = $this->durationChargeStrategy->resolveQuantity($chargeableItem->charge_model, $quantityOrDuration);
        $entry = $this->findBestPriceBookEntry($chargeableItemId, $currencyCode, $asOfDate, $tenantId, $facilityId, $payerContractId);

        if ($entry === null) {
            return [
                'chargeableItemId' => $chargeableItemId,
                'unitPrice' => 0.0,
                'quantity' => $quantity,
                'lineTotal' => 0.0,
                'currencyCode' => $currencyCode,
                'pricingStatus' => 'missing_price_book_entry',
            ];
        }

        $unitPrice = round((float) $entry->unit_price, 2);

        return [
            'chargeableItemId' => $chargeableItemId,
            'unitPrice' => $unitPrice,
            'quantity' => $quantity,
            'lineTotal' => round($unitPrice * $quantity, 2),
            'currencyCode' => $currencyCode,
            'pricingStatus' => 'priced',
        ];
    }

    private function findBestPriceBookEntry(
        string $chargeableItemId,
        string $currencyCode,
        ?string $asOfDate,
        ?string $tenantId,
        ?string $facilityId,
        ?string $payerContractId,
    ): ?PriceBookEntryModel {
        $effectiveDate = $asOfDate ?? now()->toDateTimeString();

        $query = PriceBookEntryModel::query()
            ->where('chargeable_item_id', $chargeableItemId)
            ->where('currency_code', $currencyCode)
            ->where('status', 'active')
            ->where(function (Builder $builder) use ($tenantId): void {
                $builder->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->where(function (Builder $builder) use ($facilityId): void {
                $builder->whereNull('facility_id')->orWhere('facility_id', $facilityId);
            })
            ->where(function (Builder $builder) use ($effectiveDate): void {
                $builder->whereNull('effective_from')->orWhere('effective_from', '<=', $effectiveDate);
            })
            ->where(function (Builder $builder) use ($effectiveDate): void {
                $builder->whereNull('effective_to')->orWhere('effective_to', '>=', $effectiveDate);
            });

        $this->facilityTierSupport->applyAvailabilityFilter($query, 'price_book_entries', $facilityId);

        $candidates = $query->get();
        if ($candidates->isEmpty()) {
            return null;
        }

        if ($payerContractId !== null) {
            $payerMatch = $this->mostSpecific($candidates->where('payer_contract_id', $payerContractId));
            if ($payerMatch !== null) {
                return $payerMatch;
            }
        }

        return $this->mostSpecific($candidates->whereNull('payer_contract_id'));
    }

    /**
     * @param  Collection<int, PriceBookEntryModel>  $candidates
     */
    private function mostSpecific(Collection $candidates): ?PriceBookEntryModel
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $facilitySpecific = $candidates->whereNotNull('facility_id');
        $pool = $facilitySpecific->isNotEmpty() ? $facilitySpecific : $candidates;

        return $pool->sortByDesc(fn (PriceBookEntryModel $entry): string => (string) ($entry->effective_from ?? '0000-01-01'))->first();
    }
}
