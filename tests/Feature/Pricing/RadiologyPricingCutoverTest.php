<?php

use App\Models\User;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Billing\Infrastructure\Models\PricingEngineShadowDiffModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * PricingEngine_Migration_Plan.md Phase 5: Radiology's legacy pricing path
 * is fully removed, same as Laboratory (LaboratoryPricingCutoverTest.php).
 * No flag left to gate it, no legacy fallback, no shadow-diff dispatched.
 */
uses(RefreshDatabase::class);

function makeRadiologyCutoverUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('billing.invoices.create');
    $user->givePermissionTo('billing.invoices.read');

    return $user;
}

function makeRadiologyCutoverPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Radiology',
        'last_name' => 'Cutover',
        'gender' => 'female',
        'date_of_birth' => '1990-01-01',
        'phone' => '+255700000077',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function makeRadiologyCutoverOrder(string $patientId, string $chargeableItemId): RadiologyOrderModel
{
    return RadiologyOrderModel::query()->create([
        'order_number' => 'RAD'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'radiology_procedure_catalog_item_id' => $chargeableItemId,
        'procedure_code' => 'RAD-ABD-001',
        'study_description' => 'Abdominal Ultrasound',
        'modality' => 'ultrasound',
        'completed_at' => now()->subHour()->toDateTimeString(),
        'status' => 'completed',
    ]);
}

function setUpRadiologyCutover(float $price): ClinicalCatalogItemModel
{
    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'catalog_type' => 'radiology_procedure', 'code' => 'RAD-ABD-001', 'name' => 'Abdominal Ultrasound', 'unit' => 'study', 'status' => 'active',
    ]);

    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->id = $catalogItem->id;
    $chargeableItem->fill([
        'catalog_type' => 'radiology_procedure', 'charge_model' => 'flat', 'code' => 'RAD-ABD-001', 'name' => 'Abdominal Ultrasound', 'status' => 'active',
    ]);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $catalogItem->id, 'currency_code' => 'TZS', 'unit_price' => $price, 'status' => 'active',
    ]);

    return $catalogItem;
}

it('prices radiology orders via the chargeable item, ignoring any legacy tariff', function (): void {
    $catalogItem = setUpRadiologyCutover(99000);
    $patient = makeRadiologyCutoverPatient();
    makeRadiologyCutoverOrder($patient->id, $catalogItem->id);
    // A legacy tariff for the same code, deliberately present, to prove
    // it's genuinely never consulted anymore.
    BillingServiceCatalogItemModel::query()->create([
        'service_code' => 'RAD-ABD-001', 'service_name' => 'Abdominal Ultrasound', 'service_type' => 'radiology',
        'unit' => 'study', 'base_price' => 60000, 'currency_code' => 'TZS', 'effective_from' => now()->subDay(), 'status' => 'active',
    ]);

    $candidate = $this->actingAs(makeRadiologyCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(99000.0)
        ->and($candidate['pricingStatus'])->toBe('priced')
        ->and($candidate['pricingSource'])->toBe('chargeable_item');
});

it('does not dispatch a shadow-diff comparison, since there is no legacy price left to compare against', function (): void {
    $catalogItem = setUpRadiologyCutover(99000);
    $patient = makeRadiologyCutoverPatient();
    makeRadiologyCutoverOrder($patient->id, $catalogItem->id);

    $this->actingAs(makeRadiologyCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk();

    expect(PricingEngineShadowDiffModel::query()->count())->toBe(0);
});

it('reports missing_catalog_price rather than falling back to a legacy tariff when there is no price book entry', function (): void {
    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'catalog_type' => 'radiology_procedure', 'code' => 'RAD-ABD-001', 'name' => 'Abdominal Ultrasound', 'unit' => 'study', 'status' => 'active',
    ]);
    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->id = $catalogItem->id;
    $chargeableItem->fill([
        'catalog_type' => 'radiology_procedure', 'charge_model' => 'flat', 'code' => 'RAD-ABD-001', 'name' => 'Abdominal Ultrasound', 'status' => 'active',
    ]);
    $chargeableItem->save();
    // Deliberately no PriceBookEntryModel row -- and a legacy tariff
    // present too, to prove it's genuinely never consulted as a fallback.
    BillingServiceCatalogItemModel::query()->create([
        'service_code' => 'RAD-ABD-001', 'service_name' => 'Abdominal Ultrasound', 'service_type' => 'radiology',
        'unit' => 'study', 'base_price' => 60000, 'currency_code' => 'TZS', 'effective_from' => now()->subDay(), 'status' => 'active',
    ]);

    $patient = makeRadiologyCutoverPatient();
    makeRadiologyCutoverOrder($patient->id, $catalogItem->id);

    $candidate = $this->actingAs(makeRadiologyCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(0.0)
        ->and($candidate['pricingStatus'])->toBe('missing_catalog_price')
        ->and($candidate['pricingSource'])->toBeNull();
});
