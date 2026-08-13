<?php

use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function makePricingCatalogItem(array $overrides = []): ClinicalCatalogItemModel
{
    return ClinicalCatalogItemModel::query()->create(array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'lab_test',
        'code' => 'LAB-CBC',
        'name' => 'Complete Blood Count',
        'department_id' => null,
        'category' => 'hematology',
        'unit' => 'test',
        'status' => 'active',
        'status_reason' => null,
        'metadata' => null,
    ], $overrides));
}

function makePricingBillingTariff(array $overrides = []): BillingServiceCatalogItemModel
{
    return BillingServiceCatalogItemModel::query()->create(array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'facility_tier' => null,
        'service_code' => 'LAB-CBC',
        'service_name' => 'Complete Blood Count',
        'service_type' => 'laboratory',
        'unit' => 'test',
        'base_price' => 12000,
        'currency_code' => 'TZS',
        'tax_rate_percent' => 0,
        'is_taxable' => false,
        'effective_from' => now()->subDay(),
        'effective_to' => null,
        'tariff_version' => 1,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

it('creates a chargeable item reusing the clinical catalog item id', function (): void {
    $catalogItem = makePricingCatalogItem();

    Artisan::call('pricing:backfill-chargeable-items');

    $chargeableItem = ChargeableItemModel::query()->find($catalogItem->id);

    expect($chargeableItem)->not->toBeNull()
        ->and($chargeableItem->id)->toBe($catalogItem->id)
        ->and($chargeableItem->catalog_type)->toBe('lab_test')
        ->and($chargeableItem->charge_model)->toBe('flat')
        ->and($chargeableItem->code)->toBe('LAB-CBC')
        ->and($chargeableItem->default_unit)->toBe('test');
});

it('derives per_unit charge model for formulary items and flat for everything else', function (): void {
    makePricingCatalogItem(['code' => 'LAB-CBC', 'catalog_type' => 'lab_test']);
    $formularyItem = makePricingCatalogItem(['code' => 'ATC:J01DD04', 'catalog_type' => 'formulary_item']);

    Artisan::call('pricing:backfill-chargeable-items');

    expect(ChargeableItemModel::query()->find($formularyItem->id)->charge_model)->toBe('per_unit');
});

it('backfills a price book entry linked to the chargeable item, carrying over the rate', function (): void {
    $catalogItem = makePricingCatalogItem();
    $tariff = makePricingBillingTariff(['clinical_catalog_item_id' => $catalogItem->id]);

    Artisan::call('pricing:backfill-chargeable-items');

    $priceBookEntry = PriceBookEntryModel::query()->where('chargeable_item_id', $catalogItem->id)->first();

    expect($priceBookEntry)->not->toBeNull()
        ->and((float) $priceBookEntry->unit_price)->toBe(12000.0)
        ->and($priceBookEntry->currency_code)->toBe('TZS')
        ->and($priceBookEntry->status)->toBe('active')
        ->and($priceBookEntry->payer_contract_id)->toBeNull();
});

it('does not backfill billing_service_catalog_items rows with no clinical_catalog_item_id, and reports them', function (): void {
    makePricingBillingTariff([
        'service_code' => 'BED-DAY',
        'service_name' => 'Bed Charge',
        'service_type' => 'bed_day',
        'clinical_catalog_item_id' => null,
    ]);

    Artisan::call('pricing:backfill-chargeable-items');
    $output = Artisan::output();

    expect(PriceBookEntryModel::query()->count())->toBe(0)
        ->and($output)->toContain('1 billing_service_catalog_items row(s) have no clinical_catalog_item_id')
        ->and($output)->toContain('BED-DAY');
});

it('is idempotent across repeated runs — no duplicate chargeable items or price book entries', function (): void {
    $catalogItem = makePricingCatalogItem();
    makePricingBillingTariff(['clinical_catalog_item_id' => $catalogItem->id]);

    Artisan::call('pricing:backfill-chargeable-items');
    Artisan::call('pricing:backfill-chargeable-items');
    $secondRunOutput = Artisan::output();

    expect(ChargeableItemModel::query()->count())->toBe(1)
        ->and(PriceBookEntryModel::query()->count())->toBe(1)
        ->and($secondRunOutput)->toContain('Price book entries skipped (already backfilled)');
});

it('rebuilds the supersedes chain between backfilled price book entries', function (): void {
    $catalogItem = makePricingCatalogItem();
    $oldTariff = makePricingBillingTariff([
        'clinical_catalog_item_id' => $catalogItem->id,
        'base_price' => 10000,
        'status' => 'superseded',
        'effective_from' => now()->subDays(30),
        'effective_to' => now()->subDay(),
        'tariff_version' => 1,
    ]);
    makePricingBillingTariff([
        'clinical_catalog_item_id' => $catalogItem->id,
        'base_price' => 12000,
        'status' => 'active',
        'effective_from' => now(),
        'effective_to' => null,
        'tariff_version' => 2,
        'supersedes_billing_service_catalog_item_id' => $oldTariff->id,
    ]);

    Artisan::call('pricing:backfill-chargeable-items');

    $newEntry = PriceBookEntryModel::query()->where('tariff_version', 2)->first();
    $oldEntry = PriceBookEntryModel::query()->where('tariff_version', 1)->first();

    expect($newEntry->supersedes_price_book_entry_id)->toBe($oldEntry->id);
});

it('writes nothing to the database in dry-run mode', function (): void {
    $catalogItem = makePricingCatalogItem();
    makePricingBillingTariff(['clinical_catalog_item_id' => $catalogItem->id]);

    Artisan::call('pricing:backfill-chargeable-items', ['--dry-run' => true]);

    expect(ChargeableItemModel::query()->count())->toBe(0)
        ->and(PriceBookEntryModel::query()->count())->toBe(0);
});
