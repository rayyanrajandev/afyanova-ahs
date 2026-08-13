<?php

use App\Models\User;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Billing\Infrastructure\Models\PricingEngineShadowDiffModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * PricingEngine_Migration_Plan.md Phase 5: Laboratory is the first
 * order-domain with its legacy string-match pricing path fully removed
 * (ClinicalSourceKind::LABORATORY_ORDER->isLegacyPricingRemoved() === true).
 * No flag left to gate it, no legacy fallback, no shadow-diff dispatched
 * (nothing left to compare against). An unpriced order is genuinely
 * unpriced ('missing_catalog_price'), not a silent string-match rescue.
 */
uses(RefreshDatabase::class);

function makeCutoverUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('billing.invoices.create');
    $user->givePermissionTo('billing.invoices.read');

    return $user;
}

function makeCutoverPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Cutover',
        'last_name' => 'Test',
        'gender' => 'male',
        'date_of_birth' => '1985-01-01',
        'phone' => '+255700000088',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function makeCutoverLabOrder(string $patientId, string $chargeableItemId): LaboratoryOrderModel
{
    return LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'lab_test_catalog_item_id' => $chargeableItemId,
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'resulted_at' => now()->subHour()->toDateTimeString(),
        'status' => 'completed',
    ]);
}

/**
 * 'id' is deliberately not in ChargeableItemModel::$fillable (matches the
 * production backfill's own reasoning), so ::create(['id' => ...]) silently
 * drops it under mass-assignment protection and HasUuids generates a random
 * one instead -- exactly the bug PricingBackfillChargeableItems.php avoids
 * by setting ->id directly before ->save(). Mirror that here.
 */
function makeChargeableItemWithId(string $id, array $attributes): ChargeableItemModel
{
    $item = new ChargeableItemModel();
    $item->id = $id;
    $item->fill($attributes);
    $item->save();

    return $item;
}

function setUpCutoverCatalogAndPrice(float $price): ClinicalCatalogItemModel
{
    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'catalog_type' => 'lab_test', 'code' => 'LAB-CBC', 'name' => 'CBC', 'unit' => 'test', 'status' => 'active',
    ]);

    makeChargeableItemWithId($catalogItem->id, [
        'catalog_type' => 'lab_test', 'charge_model' => 'flat', 'code' => 'LAB-CBC', 'name' => 'CBC', 'status' => 'active',
    ]);

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $catalogItem->id, 'currency_code' => 'TZS', 'unit_price' => $price, 'status' => 'active',
    ]);

    return $catalogItem;
}

it('prices laboratory orders via the chargeable item, ignoring any legacy tariff', function (): void {
    $catalogItem = setUpCutoverCatalogAndPrice(20000);
    $patient = makeCutoverPatient();
    makeCutoverLabOrder($patient->id, $catalogItem->id);
    // A legacy tariff for the same code, deliberately present, to prove
    // it's genuinely never consulted anymore -- not just deprioritized.
    BillingServiceCatalogItemModel::query()->create([
        'service_code' => 'LOINC:57021-8', 'service_name' => 'Complete Blood Count', 'service_type' => 'laboratory',
        'unit' => 'test', 'base_price' => 12000, 'currency_code' => 'TZS', 'effective_from' => now()->subDay(), 'status' => 'active',
    ]);

    $candidate = $this->actingAs(makeCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(20000.0)
        ->and($candidate['pricingStatus'])->toBe('priced')
        ->and($candidate['pricingSource'])->toBe('chargeable_item');
});

it('does not dispatch a shadow-diff comparison, since there is no legacy price left to compare against', function (): void {
    $catalogItem = setUpCutoverCatalogAndPrice(20000);
    $patient = makeCutoverPatient();
    makeCutoverLabOrder($patient->id, $catalogItem->id);

    $this->actingAs(makeCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk();

    expect(PricingEngineShadowDiffModel::query()->count())->toBe(0);
});

it('reports missing_catalog_price rather than falling back to a legacy tariff when there is no price book entry', function (): void {
    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'catalog_type' => 'lab_test', 'code' => 'LAB-CBC', 'name' => 'CBC', 'unit' => 'test', 'status' => 'active',
    ]);
    makeChargeableItemWithId($catalogItem->id, [
        'catalog_type' => 'lab_test', 'charge_model' => 'flat', 'code' => 'LAB-CBC', 'name' => 'CBC', 'status' => 'active',
    ]);
    // Deliberately no PriceBookEntryModel row -- and a legacy tariff
    // present too, to prove it's genuinely never consulted as a fallback.
    BillingServiceCatalogItemModel::query()->create([
        'service_code' => 'LOINC:57021-8', 'service_name' => 'Complete Blood Count', 'service_type' => 'laboratory',
        'unit' => 'test', 'base_price' => 12000, 'currency_code' => 'TZS', 'effective_from' => now()->subDay(), 'status' => 'active',
    ]);

    $patient = makeCutoverPatient();
    makeCutoverLabOrder($patient->id, $catalogItem->id);

    $candidate = $this->actingAs(makeCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(0.0)
        ->and($candidate['pricingStatus'])->toBe('missing_catalog_price')
        ->and($candidate['pricingSource'])->toBeNull();
});
