<?php

use App\Jobs\ShadowDiffChargeCaptureCandidateJob;
use App\Models\User;
use App\Modules\Billing\Domain\Services\ChargeResolverInterface;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Billing\Infrastructure\Models\PricingEngineShadowDiffModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeShadowDiffBillingUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('billing.invoices.create');
    $user->givePermissionTo('billing.invoices.read');

    return $user;
}

function makeShadowDiffLabTariff(array $overrides = []): BillingServiceCatalogItemModel
{
    return BillingServiceCatalogItemModel::query()->create(array_merge([
        'service_code' => 'LOINC:57021-8',
        'service_name' => 'Complete Blood Count',
        'service_type' => 'laboratory',
        'unit' => 'test',
        'base_price' => 12000,
        'currency_code' => 'TZS',
        'tax_rate_percent' => 0,
        'is_taxable' => false,
        'effective_from' => now()->subDay(),
        'effective_to' => null,
        'status' => 'active',
    ], $overrides));
}

function makeShadowDiffPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Shadow',
        'last_name' => 'Diff',
        'gender' => 'female',
        'date_of_birth' => '1990-01-01',
        'phone' => '+255700000099',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function makeShadowDiffLabOrder(string $patientId, ?string $chargeableItemId, array $overrides = []): LaboratoryOrderModel
{
    return LaboratoryOrderModel::query()->create(array_merge([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'lab_test_catalog_item_id' => $chargeableItemId,
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'resulted_at' => now()->subHour()->toDateTimeString(),
        'status' => 'completed',
    ], $overrides));
}

/**
 * Pharmacy fixtures, kept for the direct-job-construction tests above (they
 * instantiate ShadowDiffChargeCaptureCandidateJob directly and don't depend
 * on which domain dispatches it). All five order-domains (Laboratory,
 * Radiology, Clinical Procedure, Theatre, Pharmacy) now have their legacy
 * pricing path fully removed (PricingEngine_Migration_Plan.md Phase 5), so
 * none of them call dispatchShadowDiff() from the real endpoint anymore --
 * see the "does not dispatch a shadow diff" test below, which replaced the
 * old "dispatches..." test now that there's no order-domain left for it to
 * exercise.
 */
function makeShadowDiffPharmacyOrder(string $patientId, ?string $chargeableItemId, array $overrides = []): PharmacyOrderModel
{
    return PharmacyOrderModel::query()->create(array_merge([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'approved_medicine_catalog_item_id' => $chargeableItemId,
        'medication_code' => 'MED-PARACETAMOL-500',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => '1 tablet twice daily',
        'quantity_prescribed' => 20,
        'quantity_dispensed' => 20,
        'dispensed_at' => now()->subHour()->toDateTimeString(),
        'status' => 'dispensed',
    ], $overrides));
}

it('logs a matched shadow diff when the legacy and new resolvers agree', function (): void {
    $item = ChargeableItemModel::query()->create([
        'catalog_type' => 'lab_test', 'charge_model' => 'flat', 'code' => 'LAB-CBC', 'name' => 'CBC', 'status' => 'active',
    ]);
    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $item->id, 'currency_code' => 'TZS', 'unit_price' => 12000, 'status' => 'active',
    ]);

    $job = new ShadowDiffChargeCaptureCandidateJob(
        sourceKind: 'laboratory_order',
        sourceId: 'order-1',
        chargeableItemId: $item->id,
        quantityOrDuration: 1,
        performedAt: null,
        tenantId: null,
        facilityId: null,
        payerContractId: null,
        legacyCurrencyCode: 'TZS',
        legacyServiceCode: 'LAB-CBC',
        legacyUnitPrice: 12000,
        legacyPricingStatus: 'priced',
    );

    $job->handle(app(ChargeResolverInterface::class));

    $diff = PricingEngineShadowDiffModel::query()->first();
    expect($diff)->not->toBeNull()
        ->and($diff->matched)->toBeTrue()
        ->and($diff->mismatch_reason)->toBeNull()
        ->and((float) $diff->new_unit_price)->toBe(12000.0);
});

it('logs legacy_missing_new_priced when the legacy side had no price but the new one does', function (): void {
    $item = ChargeableItemModel::query()->create([
        'catalog_type' => 'lab_test', 'charge_model' => 'flat', 'code' => 'LAB-CBC', 'name' => 'CBC', 'status' => 'active',
    ]);
    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $item->id, 'currency_code' => 'TZS', 'unit_price' => 12000, 'status' => 'active',
    ]);

    $job = new ShadowDiffChargeCaptureCandidateJob(
        sourceKind: 'laboratory_order',
        sourceId: 'order-5',
        chargeableItemId: $item->id,
        quantityOrDuration: 1,
        performedAt: null,
        tenantId: null,
        facilityId: null,
        payerContractId: null,
        legacyCurrencyCode: 'TZS',
        legacyServiceCode: 'LAB-CBC',
        legacyUnitPrice: 0,
        legacyPricingStatus: 'missing_catalog_price',
    );

    $job->handle(app(ChargeResolverInterface::class));

    expect(PricingEngineShadowDiffModel::query()->first()->mismatch_reason)->toBe('legacy_missing_new_priced');
});

it('logs currency_differs when the legacy candidate currency code was not normalized', function (): void {
    // Currency is an input to resolution, not an independently resolved
    // output, so this only fires when the legacy currency string itself
    // wasn't uppercased/trimmed -- a data-hygiene signal about the legacy
    // candidate, not a genuine disagreement between the two resolvers.
    $item = ChargeableItemModel::query()->create([
        'catalog_type' => 'lab_test', 'charge_model' => 'flat', 'code' => 'LAB-CBC', 'name' => 'CBC', 'status' => 'active',
    ]);
    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $item->id, 'currency_code' => 'TZS', 'unit_price' => 12000, 'status' => 'active',
    ]);

    $job = new ShadowDiffChargeCaptureCandidateJob(
        sourceKind: 'laboratory_order',
        sourceId: 'order-6',
        chargeableItemId: $item->id,
        quantityOrDuration: 1,
        performedAt: null,
        tenantId: null,
        facilityId: null,
        payerContractId: null,
        legacyCurrencyCode: ' tzs',
        legacyServiceCode: 'LAB-CBC',
        legacyUnitPrice: 12000,
        legacyPricingStatus: 'priced',
    );

    $job->handle(app(ChargeResolverInterface::class));

    expect(PricingEngineShadowDiffModel::query()->first()->mismatch_reason)->toBe('currency_differs');
});

it('logs a price_differs mismatch when the new resolver disagrees on price', function (): void {
    $item = ChargeableItemModel::query()->create([
        'catalog_type' => 'lab_test', 'charge_model' => 'flat', 'code' => 'LAB-CBC', 'name' => 'CBC', 'status' => 'active',
    ]);
    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $item->id, 'currency_code' => 'TZS', 'unit_price' => 15000, 'status' => 'active',
    ]);

    $job = new ShadowDiffChargeCaptureCandidateJob(
        sourceKind: 'laboratory_order',
        sourceId: 'order-2',
        chargeableItemId: $item->id,
        quantityOrDuration: 1,
        performedAt: null,
        tenantId: null,
        facilityId: null,
        payerContractId: null,
        legacyCurrencyCode: 'TZS',
        legacyServiceCode: 'LAB-CBC',
        legacyUnitPrice: 12000,
        legacyPricingStatus: 'priced',
    );

    $job->handle(app(ChargeResolverInterface::class));

    $diff = PricingEngineShadowDiffModel::query()->first();
    expect($diff->matched)->toBeFalse()
        ->and($diff->mismatch_reason)->toBe('price_differs');
});

it('logs legacy_priced_new_missing when the new resolver has no price book entry at all', function (): void {
    $item = ChargeableItemModel::query()->create([
        'catalog_type' => 'lab_test', 'charge_model' => 'flat', 'code' => 'LAB-CBC', 'name' => 'CBC', 'status' => 'active',
    ]);

    $job = new ShadowDiffChargeCaptureCandidateJob(
        sourceKind: 'laboratory_order',
        sourceId: 'order-3',
        chargeableItemId: $item->id,
        quantityOrDuration: 1,
        performedAt: null,
        tenantId: null,
        facilityId: null,
        payerContractId: null,
        legacyCurrencyCode: 'TZS',
        legacyServiceCode: 'LAB-CBC',
        legacyUnitPrice: 12000,
        legacyPricingStatus: 'priced',
    );

    $job->handle(app(ChargeResolverInterface::class));

    expect(PricingEngineShadowDiffModel::query()->first()->mismatch_reason)->toBe('legacy_priced_new_missing');
});

it('treats both sides being unpriced as a match, not a mismatch', function (): void {
    $item = ChargeableItemModel::query()->create([
        'catalog_type' => 'lab_test', 'charge_model' => 'flat', 'code' => 'LAB-CBC', 'name' => 'CBC', 'status' => 'active',
    ]);

    $job = new ShadowDiffChargeCaptureCandidateJob(
        sourceKind: 'laboratory_order',
        sourceId: 'order-4',
        chargeableItemId: $item->id,
        quantityOrDuration: 1,
        performedAt: null,
        tenantId: null,
        facilityId: null,
        payerContractId: null,
        legacyCurrencyCode: 'TZS',
        legacyServiceCode: 'LAB-CBC',
        legacyUnitPrice: 0,
        legacyPricingStatus: 'missing_catalog_price',
    );

    $job->handle(app(ChargeResolverInterface::class));

    $diff = PricingEngineShadowDiffModel::query()->first();
    expect($diff->matched)->toBeTrue()
        ->and($diff->mismatch_reason)->toBeNull();
});

it('writes nothing when there is no chargeable item id to compare against yet', function (): void {
    $job = new ShadowDiffChargeCaptureCandidateJob(
        sourceKind: 'appointment_consultation',
        sourceId: 'appt-1',
        chargeableItemId: null,
        quantityOrDuration: 1,
        performedAt: null,
        tenantId: null,
        facilityId: null,
        payerContractId: null,
        legacyCurrencyCode: 'TZS',
        legacyServiceCode: 'CONSULT-OUTPATIENT',
        legacyUnitPrice: 35000,
        legacyPricingStatus: 'priced',
    );

    $job->handle(app(ChargeResolverInterface::class));

    expect(PricingEngineShadowDiffModel::query()->count())->toBe(0);
});

it('logs a mismatch instead of crashing when the referenced catalog item has no chargeable_items row yet', function (): void {
    // Regression test: an order's catalog FK can point at a real
    // platform_clinical_catalog_items row that simply hasn't been backfilled
    // into chargeable_items yet (Phase 1 hasn't run, or this item was
    // created after the last backfill). pricing_engine_shadow_diffs must not
    // have a hard FK to chargeable_items for exactly this reason -- caught
    // by the full suite as a real SQLite FK-constraint failure the first
    // time this file's own tests didn't happen to exercise it.
    $unbackfilledCatalogItemId = (string) Illuminate\Support\Str::orderedUuid();

    $job = new ShadowDiffChargeCaptureCandidateJob(
        sourceKind: 'radiology_order',
        sourceId: 'order-7',
        chargeableItemId: $unbackfilledCatalogItemId,
        quantityOrDuration: 1,
        performedAt: null,
        tenantId: null,
        facilityId: null,
        payerContractId: null,
        legacyCurrencyCode: 'TZS',
        legacyServiceCode: 'RAD-USA-001',
        legacyUnitPrice: 60000,
        legacyPricingStatus: 'priced',
    );

    $job->handle(app(ChargeResolverInterface::class));

    $diff = PricingEngineShadowDiffModel::query()->first();
    expect($diff)->not->toBeNull()
        ->and($diff->chargeable_item_id)->toBe($unbackfilledCatalogItemId)
        ->and($diff->matched)->toBeFalse()
        ->and($diff->mismatch_reason)->toBe('legacy_priced_new_missing')
        ->and($diff->new_pricing_status)->toBe('missing_chargeable_item');
});

it('does not dispatch a shadow diff job for any order candidate when hitting the real endpoint, since every order-domain is cut over', function (): void {
    Queue::fake();

    $patient = makeShadowDiffPatient();
    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'catalog_type' => 'formulary_item', 'code' => 'MED-PARACETAMOL-500', 'name' => 'Paracetamol 500mg', 'unit' => 'tablet', 'status' => 'active',
    ]);
    makeShadowDiffPharmacyOrder($patient->id, $catalogItem->id);

    $user = makeShadowDiffBillingUser();
    $this->actingAs($user)
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk();

    Queue::assertNotPushed(ShadowDiffChargeCaptureCandidateJob::class);
});
