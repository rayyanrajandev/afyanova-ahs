<?php

use App\Models\User;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Billing\Infrastructure\Models\PricingEngineShadowDiffModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * PricingEngine_Migration_Plan.md Phase 5: Pharmacy's legacy pricing path
 * is fully removed, the last of the five order-domains (Bed-day is
 * structurally different -- net-new capability, not a migration -- and
 * stays flag-gated). Pharmacy is also the first domain where
 * charge_model=per_unit actually matters end-to-end (Laboratory/Radiology
 * are flat -- quantity is always 1 regardless of what's passed in). This
 * file specifically proves quantity dispensed drives the billed quantity
 * through the new resolver.
 */
function makePharmacyCutoverUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('billing.invoices.create');
    $user->givePermissionTo('billing.invoices.read');

    return $user;
}

function makePharmacyCutoverPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Pharmacy',
        'last_name' => 'Cutover',
        'gender' => 'male',
        'date_of_birth' => '1988-01-01',
        'phone' => '+255700000066',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function makePharmacyCutoverOrder(string $patientId, string $chargeableItemId, float $quantityDispensed): PharmacyOrderModel
{
    return PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'approved_medicine_catalog_item_id' => $chargeableItemId,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => 'Take 1 tablet twice daily',
        'quantity_prescribed' => $quantityDispensed,
        'quantity_dispensed' => $quantityDispensed,
        'dispensed_at' => now()->subHour()->toDateTimeString(),
        'status' => 'dispensed',
        'entry_state' => 'active',
    ]);
}

function setUpPharmacyCutover(float $legacyUnitPrice, float $newResolverUnitPrice): ClinicalCatalogItemModel
{
    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'catalog_type' => 'formulary_item', 'code' => 'ATC:N02BE01', 'name' => 'Paracetamol 500mg', 'unit' => 'tablet', 'status' => 'active',
    ]);

    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->id = $catalogItem->id;
    $chargeableItem->fill([
        'catalog_type' => 'formulary_item', 'charge_model' => 'per_unit', 'code' => 'ATC:N02BE01', 'name' => 'Paracetamol 500mg', 'status' => 'active',
    ]);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $catalogItem->id, 'currency_code' => 'TZS', 'unit_price' => $newResolverUnitPrice, 'status' => 'active',
    ]);

    BillingServiceCatalogItemModel::query()->create([
        'service_code' => 'ATC:N02BE01', 'service_name' => 'Paracetamol 500mg', 'service_type' => 'pharmacy',
        'unit' => 'tablet', 'base_price' => $legacyUnitPrice, 'currency_code' => 'TZS', 'effective_from' => now()->subDay(), 'status' => 'active',
    ]);

    return $catalogItem;
}

it('prices pharmacy orders via the chargeable item, ignoring any legacy tariff, still billing the real quantity dispensed', function (): void {
    // A legacy tariff for the same code, deliberately present (via
    // setUpPharmacyCutover), to prove it's genuinely never consulted anymore.
    $catalogItem = setUpPharmacyCutover(legacyUnitPrice: 200, newResolverUnitPrice: 250);
    $patient = makePharmacyCutoverPatient();
    makePharmacyCutoverOrder($patient->id, $catalogItem->id, quantityDispensed: 20);

    $candidate = $this->actingAs(makePharmacyCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(250.0)
        ->and((float) $candidate['quantity'])->toBe(20.0)
        ->and((float) $candidate['lineTotal'])->toBe(5000.0)
        ->and($candidate['pricingStatus'])->toBe('priced')
        ->and($candidate['pricingSource'])->toBe('chargeable_item');
});

it('bills a fractional quantity dispensed correctly (per_unit does not round like per_day)', function (): void {
    $catalogItem = setUpPharmacyCutover(legacyUnitPrice: 200, newResolverUnitPrice: 250);
    $patient = makePharmacyCutoverPatient();
    makePharmacyCutoverOrder($patient->id, $catalogItem->id, quantityDispensed: 2.5);

    $candidate = $this->actingAs(makePharmacyCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['quantity'])->toBe(2.5)
        ->and((float) $candidate['lineTotal'])->toBe(625.0);
});

it('does not dispatch a shadow-diff comparison for pharmacy, since there is no legacy price left to compare against', function (): void {
    $catalogItem = setUpPharmacyCutover(legacyUnitPrice: 200, newResolverUnitPrice: 250);
    $patient = makePharmacyCutoverPatient();
    makePharmacyCutoverOrder($patient->id, $catalogItem->id, quantityDispensed: 20);

    $this->actingAs(makePharmacyCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk();

    expect(PricingEngineShadowDiffModel::query()->count())->toBe(0);
});

it('reports missing_catalog_price for pharmacy rather than falling back to a legacy tariff when there is no price book entry', function (): void {
    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'catalog_type' => 'formulary_item', 'code' => 'ATC:N02BE01', 'name' => 'Paracetamol 500mg', 'unit' => 'tablet', 'status' => 'active',
    ]);
    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->id = $catalogItem->id;
    $chargeableItem->fill([
        'catalog_type' => 'formulary_item', 'charge_model' => 'per_unit', 'code' => 'ATC:N02BE01', 'name' => 'Paracetamol 500mg', 'status' => 'active',
    ]);
    $chargeableItem->save();
    // Deliberately no PriceBookEntryModel row -- and a legacy tariff
    // present too, to prove it's genuinely never consulted as a fallback.
    BillingServiceCatalogItemModel::query()->create([
        'service_code' => 'ATC:N02BE01', 'service_name' => 'Paracetamol 500mg', 'service_type' => 'pharmacy',
        'unit' => 'tablet', 'base_price' => 200, 'currency_code' => 'TZS', 'effective_from' => now()->subDay(), 'status' => 'active',
    ]);

    $patient = makePharmacyCutoverPatient();
    makePharmacyCutoverOrder($patient->id, $catalogItem->id, quantityDispensed: 20);

    $candidate = $this->actingAs(makePharmacyCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(0.0)
        ->and($candidate['pricingStatus'])->toBe('missing_catalog_price')
        ->and($candidate['pricingSource'])->toBeNull();
});
