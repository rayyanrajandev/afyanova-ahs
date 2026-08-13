<?php

use App\Modules\Billing\Domain\Services\ChargeResolverInterface;
use App\Modules\Billing\Infrastructure\Models\BillingPayerContractModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeChargeResolverItem(array $overrides = []): ChargeableItemModel
{
    return ChargeableItemModel::query()->create(array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'lab_test',
        'charge_model' => 'flat',
        'code' => 'LAB-CBC',
        'name' => 'Complete Blood Count',
        'default_unit' => 'test',
        'status' => 'active',
    ], $overrides));
}

function makeChargeResolverPrice(string $chargeableItemId, array $overrides = []): PriceBookEntryModel
{
    return PriceBookEntryModel::query()->create(array_merge([
        'chargeable_item_id' => $chargeableItemId,
        'tenant_id' => null,
        'facility_id' => null,
        'payer_contract_id' => null,
        'currency_code' => 'TZS',
        'unit_price' => 12000,
        'tax_rate_percent' => 0,
        'is_taxable' => false,
        'effective_from' => null,
        'effective_to' => null,
        'tariff_version' => 1,
        'status' => 'active',
    ], $overrides));
}

it('resolves a flat-priced item to quantity 1 regardless of duration passed in', function (): void {
    $item = makeChargeResolverItem();
    makeChargeResolverPrice($item->id, ['unit_price' => 15000]);

    $result = app(ChargeResolverInterface::class)->resolvePrice(
        chargeableItemId: $item->id,
        quantityOrDuration: 999,
        asOfDate: null,
        tenantId: null,
        facilityId: null,
        payerContractId: null,
        currencyCode: 'TZS',
    );

    expect($result['pricingStatus'])->toBe('priced')
        ->and($result['unitPrice'])->toBe(15000.0)
        ->and($result['quantity'])->toBe(1.0)
        ->and($result['lineTotal'])->toBe(15000.0)
        ->and($result['currencyCode'])->toBe('TZS');
});

it('resolves a per_day item quantity via the duration charge strategy', function (): void {
    $item = makeChargeResolverItem(['catalog_type' => 'bed_day', 'charge_model' => 'per_day', 'code' => 'BED-ICU']);
    makeChargeResolverPrice($item->id, ['unit_price' => 20000]);

    $result = app(ChargeResolverInterface::class)->resolvePrice(
        chargeableItemId: $item->id,
        quantityOrDuration: 50, // 50 hours elapsed -> ceil(50/24) = 3 days
        asOfDate: null,
        tenantId: null,
        facilityId: null,
        payerContractId: null,
        currencyCode: 'TZS',
    );

    expect($result['quantity'])->toBe(3.0)
        ->and($result['lineTotal'])->toBe(60000.0);
});

it('respects date-versioned pricing, picking the entry active as of the given date', function (): void {
    $item = makeChargeResolverItem();
    makeChargeResolverPrice($item->id, [
        'unit_price' => 10000,
        'effective_from' => '2026-01-01 00:00:00',
        'effective_to' => '2026-06-30 23:59:59',
        'tariff_version' => 1,
    ]);
    makeChargeResolverPrice($item->id, [
        'unit_price' => 13000,
        'effective_from' => '2026-07-01 00:00:00',
        'effective_to' => null,
        'tariff_version' => 2,
    ]);

    $resolver = app(ChargeResolverInterface::class);

    $duringOldWindow = $resolver->resolvePrice($item->id, 1, '2026-03-15 00:00:00', null, null, null, 'TZS');
    $duringNewWindow = $resolver->resolvePrice($item->id, 1, '2026-07-15 00:00:00', null, null, null, 'TZS');

    expect($duringOldWindow['unitPrice'])->toBe(10000.0)
        ->and($duringNewWindow['unitPrice'])->toBe(13000.0);
});

it('prefers a payer-specific price over the self-pay rate when a payer contract matches', function (): void {
    $item = makeChargeResolverItem();
    $payerContract = BillingPayerContractModel::query()->create([
        'contract_code' => 'NHIF-CR-2026',
        'contract_name' => 'NHIF Charge Resolver Test Contract',
        'payer_type' => 'insurance',
        'payer_name' => 'NHIF',
        'currency_code' => 'TZS',
        'default_coverage_percent' => 80,
        'default_copay_type' => 'percentage',
        'default_copay_value' => 20,
        'requires_pre_authorization' => false,
        'effective_from' => now()->subDay()->toDateTimeString(),
        'effective_to' => null,
        'status' => 'active',
    ]);

    makeChargeResolverPrice($item->id, ['unit_price' => 12000]);
    makeChargeResolverPrice($item->id, ['unit_price' => 9000, 'payer_contract_id' => $payerContract->id]);

    $resolver = app(ChargeResolverInterface::class);

    $selfPay = $resolver->resolvePrice($item->id, 1, null, null, null, null, 'TZS');
    $withPayer = $resolver->resolvePrice($item->id, 1, null, null, null, $payerContract->id, 'TZS');

    expect($selfPay['unitPrice'])->toBe(12000.0)
        ->and($withPayer['unitPrice'])->toBe(9000.0);
});

it('falls back to the self-pay rate when the requested payer contract has no override', function (): void {
    $item = makeChargeResolverItem();
    makeChargeResolverPrice($item->id, ['unit_price' => 12000]);

    $result = app(ChargeResolverInterface::class)->resolvePrice(
        $item->id, 1, null, null, null, 'some-payer-with-no-override', 'TZS',
    );

    expect($result['unitPrice'])->toBe(12000.0)
        ->and($result['pricingStatus'])->toBe('priced');
});

it('reports missing_chargeable_item when the chargeable item id does not exist', function (): void {
    $result = app(ChargeResolverInterface::class)->resolvePrice(
        (string) Illuminate\Support\Str::orderedUuid(), 1, null, null, null, null, 'TZS',
    );

    expect($result['pricingStatus'])->toBe('missing_chargeable_item')
        ->and($result['unitPrice'])->toBe(0.0);
});

it('reports missing_price_book_entry when the chargeable item has no matching active price', function (): void {
    $item = makeChargeResolverItem();

    $result = app(ChargeResolverInterface::class)->resolvePrice(
        $item->id, 1, null, null, null, null, 'TZS',
    );

    expect($result['pricingStatus'])->toBe('missing_price_book_entry')
        ->and($result['unitPrice'])->toBe(0.0);
});

it('does not match a price book entry priced in a different currency', function (): void {
    $item = makeChargeResolverItem();
    makeChargeResolverPrice($item->id, ['currency_code' => 'KES', 'unit_price' => 5000]);

    $result = app(ChargeResolverInterface::class)->resolvePrice(
        $item->id, 1, null, null, null, null, 'TZS',
    );

    expect($result['pricingStatus'])->toBe('missing_price_book_entry');
});
