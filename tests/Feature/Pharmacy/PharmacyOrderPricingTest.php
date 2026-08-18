<?php

/**
 * A prescribed medicine keeps its price across a reload.
 *
 * The clinician workspace computed `unitPrice x quantity` locally when the order
 * was placed and the API returned no price at all, so "Prescribed Medications"
 * showed a figure during the session and a dash forever afterwards. The price is
 * now resolved from the same billing link the prescribing catalog reads.
 */

use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Pharmacy\Presentation\Http\Transformers\PharmacyOrderResponseTransformer;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pricedMedicineCatalogItem(float $unitPrice): ClinicalCatalogItemModel
{
    $item = ClinicalCatalogItemModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'approved_medicine',
        'code' => 'MED-'.strtoupper(Str::random(6)),
        'name' => 'Amoxicillin 500mg',
        'category' => 'antibiotic',
        'status' => 'active',
    ]);

    $chargeable = ChargeableItemModel::query()->create([
        'clinical_catalog_item_id' => $item->id,
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'approved_medicine',
        'code' => $item->code,
        'name' => $item->name,
        'category' => 'antibiotic',
        'status' => 'active',
    ]);

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $chargeable->id,
        'tenant_id' => null,
        'facility_id' => null,
        'currency_code' => 'TZS',
        'unit_price' => $unitPrice,
        'effective_from' => now()->subDay(),
        'status' => 'active',
    ]);

    return $item;
}

/**
 * @return array<string, mixed>
 */
function pharmacyOrderRow(?string $catalogItemId, ?float $quantity): array
{
    return [
        'id' => (string) Str::uuid(),
        'order_number' => 'PH'.strtoupper(Str::random(6)),
        'patient_id' => (string) Str::uuid(),
        'approved_medicine_catalog_item_id' => $catalogItemId,
        'medication_name' => 'Amoxicillin 500mg',
        'quantity_prescribed' => $quantity,
        'prescribed_unit' => 'tabs',
        'status' => 'pending',
    ];
}

it('prices a prescribed medicine from its billing link', function (): void {
    $item = pricedMedicineCatalogItem(1500.0);

    $transformed = PharmacyOrderResponseTransformer::transform(
        pharmacyOrderRow($item->id, 21.0)
    );

    expect($transformed['unitPrice'])->toBe(1500.0);
    // What the patient is billed, not what one tablet costs.
    expect($transformed['totalPrice'])->toBe(31500.0);
    expect($transformed['quantityPrescribed'])->toBe(21.0);
    expect($transformed['prescribedUnit'])->toBe('tabs');
});

it('falls back to the unit price when no quantity was recorded', function (): void {
    $item = pricedMedicineCatalogItem(800.0);

    $transformed = PharmacyOrderResponseTransformer::transform(
        pharmacyOrderRow($item->id, null)
    );

    expect($transformed['unitPrice'])->toBe(800.0);
    expect($transformed['totalPrice'])->toBe(800.0);
});

it('reports an unpriced medicine as unknown rather than free', function (): void {
    // A zero would read as "no charge" on the chart. Null renders as an em dash.
    $unpriced = ClinicalCatalogItemModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'approved_medicine',
        'code' => 'MED-'.strtoupper(Str::random(6)),
        'name' => 'Unpriced Medicine',
        'category' => 'other',
        'status' => 'active',
    ]);

    $transformed = PharmacyOrderResponseTransformer::transform(
        pharmacyOrderRow($unpriced->id, 10.0)
    );

    expect($transformed['unitPrice'])->toBeNull();
    expect($transformed['totalPrice'])->toBeNull();
});

it('reports no price for an order with no catalog item at all', function (): void {
    $transformed = PharmacyOrderResponseTransformer::transform(
        pharmacyOrderRow(null, 10.0)
    );

    expect($transformed['unitPrice'])->toBeNull();
    expect($transformed['totalPrice'])->toBeNull();
});
