<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Revenue\Application\Support\ServiceChargePricer;

/**
 * The price list a cashier may charge from directly.
 *
 * Deliberately not the whole catalogue. Clinically ordered services —
 * medicines, lab tests, imaging, procedures — are excluded, because their
 * charge belongs to the order that requested them: the prescriber decides a
 * patient needs 21 tablets and the pharmacist decides what is dispensed, and a
 * cashier has no basis for either figure. Of the 239 items in a typical
 * catalogue, 237 are of that kind, so searching all of them offered a counter
 * clerk a list of drugs to price by hand.
 *
 * What remains is what a counter legitimately sells on its own: consultation,
 * and whatever forms, cards or certificates a facility adds. See
 * config/revenue.php for the exclusion list and how to change it.
 *
 * Returns the resolved price rather than the catalogue's face value: an item
 * can be priced differently by facility, by tier and by date, and a cashier
 * quoting a number the charge will not match is how a queue turns into an
 * argument.
 *
 * Items with no resolvable price are returned too, marked unpriced. Hiding
 * them would leave the cashier searching for something they can see in the
 * catalogue and cannot find here.
 */
class SearchChargeableItemsUseCase
{
    private const LIMIT = 25;

    public function __construct(
        private readonly ServiceChargePricer $pricer,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
        private readonly DefaultCurrencyResolverInterface $currencyResolver,
    ) {}

    /**
     * @return array{data: array<int, array<string, mixed>>}
     */
    public function execute(?string $search = null, ?string $catalogType = null): array
    {
        $tenantId = $this->scopeContext->tenantId();
        $facilityId = $this->scopeContext->facilityId();
        $currencyCode = $this->currencyResolver->resolve();

        $excludedTypes = (array) config('revenue.counter_charge_excluded_catalog_types', []);

        $items = ChargeableItemModel::query()
            ->where('status', 'active')
            ->when(
                $excludedTypes !== [],
                fn ($q) => $q->whereNotIn('catalog_type', $excludedTypes),
            )
            ->when($facilityId, fn ($q) => $q->where(
                fn ($inner) => $inner->whereNull('facility_id')->orWhere('facility_id', $facilityId),
            ))
            ->when($catalogType, fn ($q, $type) => $q->where('catalog_type', $type))
            ->when(
                $search !== null && trim($search) !== '',
                function ($q) use ($search): void {
                    $needle = '%'.strtoupper(trim($search)).'%';
                    $q->where(function ($inner) use ($needle): void {
                        $inner->whereRaw('UPPER(name) LIKE ?', [$needle])
                            ->orWhereRaw('UPPER(code) LIKE ?', [$needle]);
                    });
                },
            )
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return [
            'data' => $items->map(function (ChargeableItemModel $item) use (
                $tenantId, $facilityId, $currencyCode
            ): array {
                $priced = $this->pricer->price(
                    chargeableItemId: (string) $item->id,
                    quantity: 1.0,
                    currencyCode: $currencyCode,
                    tenantId: $tenantId,
                    facilityId: $facilityId,
                );

                return [
                    'id' => (string) $item->id,
                    'code' => (string) $item->code,
                    'name' => (string) $item->name,
                    'catalogType' => (string) $item->catalog_type,
                    'unit' => $item->default_unit,
                    'currencyCode' => $currencyCode,
                    'unitPrice' => $priced->isPriced() ? $priced->netAmount->toDecimalString() : null,
                    'isPriced' => $priced->isPriced(),
                ];
            })->all(),
        ];
    }
}
