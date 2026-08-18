<?php

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Application\UseCases\CreateInventoryBatchUseCase;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\Pharmacy\Presentation\Http\Transformers\PharmacyMedicationAvailabilityResponseTransformer;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Pharmaceutical inventory is required to link to an active formulary item, so
 * a medicine fixture has to bring one with it.
 */
function batchVisibilityItem(array $overrides = []): InventoryItemModel
{
    $attributes = array_merge([
        'item_code' => 'INV-'.strtoupper(Str::random(8)),
        'item_name' => 'Paracetamol 500mg',
        'category' => 'pharmaceutical',
        'unit' => 'tablet',
        'current_stock' => 0,
        'reorder_level' => 10,
        'status' => 'active',
    ], $overrides);

    if (($attributes['category'] ?? null) === 'pharmaceutical'
        && ! array_key_exists('clinical_catalog_item_id', $attributes)) {
        $attributes['clinical_catalog_item_id'] = ClinicalCatalogItemModel::query()->create([
            'catalog_type' => 'formulary_item',
            'code' => 'ATC:'.strtoupper(Str::random(6)),
            'name' => $attributes['item_name'],
            'category' => 'analgesics',
            'unit' => $attributes['unit'],
            'status' => 'active',
        ])->id;
    }

    return InventoryItemModel::query()->create($attributes);
}

function batchVisibilityBatch(InventoryItemModel $item, array $overrides = []): InventoryBatchModel
{
    return InventoryBatchModel::query()->create(array_merge([
        'item_id' => $item->id,
        'internal_batch_number' => 'IB-'.strtoupper(Str::random(10)),
        'batch_number' => 'LOT-A1',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 50,
        'status' => 'available',
    ], $overrides));
}

/**
 * availability() computed the FEFO batch list all along; enrichItemAvailability
 * dropped it before the transformer could read it, so pharmacy's lot selector
 * could never render at any stock level and issueExactBatch() was unreachable
 * from the workspace.
 */
it('carries the batch list through to the pharmacy payload', function (): void {
    $item = batchVisibilityItem(['current_stock' => 50]);
    batchVisibilityBatch($item);

    $enriched = app(InventoryBatchStockService::class)
        ->enrichItemAvailability(InventoryItemModel::query()->find($item->id)->toArray());

    expect($enriched['available_batches'])->toHaveCount(1);

    $payload = PharmacyMedicationAvailabilityResponseTransformer::transform($enriched);

    expect($payload['availableBatches'])->toHaveCount(1)
        ->and($payload['availableBatches'][0]['batchNumber'])->toBe('LOT-A1')
        ->and($payload['availableBatches'][0]['expiryDate'])->not->toBeNull()
        ->and($payload['batchTrackingMode'])->toBe('tracked');
});

it('orders the batches earliest expiry first, undated last', function (): void {
    $item = batchVisibilityItem(['current_stock' => 150]);
    batchVisibilityBatch($item, ['batch_number' => 'LOT-LATE', 'expiry_date' => now()->addYears(2)->toDateString()]);
    batchVisibilityBatch($item, ['batch_number' => 'LOT-SOON', 'expiry_date' => now()->addMonth()->toDateString()]);
    batchVisibilityBatch($item, ['batch_number' => 'LOT-NONE', 'expiry_date' => null]);

    $enriched = app(InventoryBatchStockService::class)
        ->enrichItemAvailability(InventoryItemModel::query()->find($item->id)->toArray());

    $order = array_column($enriched['available_batches'], 'batch_number');

    // This is the FEFO order the dispenser is choosing from, so the soonest
    // expiry has to be the first option offered.
    expect($order)->toBe(['LOT-SOON', 'LOT-LATE', 'LOT-NONE']);
});

it('tells an unbatched item from one whose every lot is unusable', function (): void {
    $untracked = batchVisibilityItem(['current_stock' => 20, 'category' => 'medical_consumable']);
    $enriched = app(InventoryBatchStockService::class)
        ->enrichItemAvailability(InventoryItemModel::query()->find($untracked->id)->toArray());
    $payload = PharmacyMedicationAvailabilityResponseTransformer::transform($enriched);

    expect($payload['hasBatchRecords'])->toBeFalse()
        ->and($payload['availableBatches'])->toBeEmpty();

    // An expired lot is a batch record that yields nothing dispensable. The
    // dispense tab used to report both of these as "main dispensary stock".
    $expired = batchVisibilityItem(['current_stock' => 50]);
    batchVisibilityBatch($expired, ['expiry_date' => now()->subDay()->toDateString()]);

    $enrichedExpired = app(InventoryBatchStockService::class)
        ->enrichItemAvailability(InventoryItemModel::query()->find($expired->id)->toArray());
    $payloadExpired = PharmacyMedicationAvailabilityResponseTransformer::transform($enrichedExpired);

    expect($payloadExpired['hasBatchRecords'])->toBeTrue()
        ->and($payloadExpired['availableBatches'])->toBeEmpty();
});

/**
 * The receiving path has always refused an undated expiry-sensitive receipt.
 * Creating a batch directly went around that check into the same tables.
 */
it('refuses an undated batch for expiry-sensitive stock', function (): void {
    $item = batchVisibilityItem();

    expect(fn () => app(CreateInventoryBatchUseCase::class)->execute([
        'item_id' => $item->id,
        'batch_number' => 'LOT-B2',
        'quantity' => 10,
    ]))->toThrow(
        InventoryStockOperationValidationException::class,
    );
});

it('accepts an undated batch for stock that does not expire', function (): void {
    $gloves = batchVisibilityItem([
        'item_name' => 'Examination Gloves',
        'category' => 'medical_consumable',
    ]);

    $batch = app(CreateInventoryBatchUseCase::class)->execute([
        'item_id' => $gloves->id,
        'batch_number' => 'LOT-C3',
        'quantity' => 100,
    ]);

    // Demanding a date here would only teach receiving staff to type a
    // placeholder, which is the failure the requirement exists to prevent.
    expect($batch['expiry_date'] ?? null)->toBeNull();
});

it('accepts a dated batch for expiry-sensitive stock', function (): void {
    $item = batchVisibilityItem();

    $batch = app(CreateInventoryBatchUseCase::class)->execute([
        'item_id' => $item->id,
        'batch_number' => 'LOT-D4',
        'quantity' => 10,
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    expect($batch['expiry_date'])->not->toBeNull();
});
