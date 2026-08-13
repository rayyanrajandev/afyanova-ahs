<?php

use App\Models\User;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * PricingEngine_Migration_Plan.md Phase 5: Bed-day is the seventh and last
 * domain -- its legacy string-match pricing path is now fully removed too,
 * same as every order-domain and Consultation before it. No flag left to
 * gate it (both pricing.engine.v2 and pricing.engine.v2.bed_day are gone
 * from config entirely), no legacy fallback. A bed with no chargeable_item
 * assigned is genuinely unpriced ('missing_catalog_price'), not a silent
 * string-match rescue.
 */
uses(RefreshDatabase::class);

function makeBedDayCutoverUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('billing.invoices.create');
    $user->givePermissionTo('billing.invoices.read');

    return $user;
}

function makeBedDayCutoverPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'BedDay', 'last_name' => 'Cutover', 'gender' => 'female',
        'date_of_birth' => '1990-01-01', 'phone' => '+255700000033', 'country_code' => 'TZ', 'status' => 'active',
    ]);
}

function makeBedDayCutoverBed(?string $chargeableItemId = null): FacilityResourceModel
{
    return FacilityResourceModel::query()->create([
        'resource_type' => 'ward_bed', 'code' => 'BED'.strtoupper(Str::random(6)),
        'name' => 'General Ward A - Bed 1', 'ward_name' => 'General Ward A', 'bed_number' => 'Bed 1',
        'status' => 'active', 'chargeable_item_id' => $chargeableItemId,
    ]);
}

function makeBedDayCutoverAdmission(string $patientId, string $bedResourceId): AdmissionModel
{
    return AdmissionModel::query()->create([
        'admission_number' => 'ADM'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'ward' => 'General Ward A',
        'bed' => 'Bed 1',
        'bed_resource_id' => $bedResourceId,
        'admitted_at' => now()->subHours(2)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Observation',
        'status' => 'admitted',
    ]);
}

function setUpBedDayCutoverPricing(float $legacyPrice, float $newResolverPrice): string
{
    // A legacy tariff for the same ward, deliberately present, to prove
    // it's genuinely never consulted anymore.
    BillingServiceCatalogItemModel::query()->create([
        'service_code' => 'BED-GENERAL-WARD-A', 'service_name' => 'Bed Charge - General Ward A', 'service_type' => 'bed_day',
        'unit' => 'day', 'base_price' => $legacyPrice, 'currency_code' => 'TZS', 'effective_from' => now()->subDay(), 'status' => 'active',
    ]);

    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->fill(['catalog_type' => 'bed_day', 'charge_model' => 'flat', 'code' => 'BED-GENERAL-WARD-A', 'name' => 'Bed Charge - General Ward A', 'status' => 'active']);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id, 'currency_code' => 'TZS', 'unit_price' => $newResolverPrice, 'status' => 'active',
    ]);

    return $chargeableItem->id;
}

it('prices a bed-day via the chargeable item once the bed has one assigned, ignoring any legacy tariff', function (): void {
    $chargeableItemId = setUpBedDayCutoverPricing(legacyPrice: 20000, newResolverPrice: 30000);
    $patient = makeBedDayCutoverPatient();
    $bed = makeBedDayCutoverBed($chargeableItemId);
    makeBedDayCutoverAdmission($patient->id, $bed->id);

    $candidate = $this->actingAs(makeBedDayCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(30000.0)
        ->and($candidate['pricingStatus'])->toBe('priced')
        ->and($candidate['pricingSource'])->toBe('chargeable_item');
});

it('reports missing_catalog_price rather than falling back to a legacy tariff when the bed has no chargeable_item assigned', function (): void {
    setUpBedDayCutoverPricing(legacyPrice: 20000, newResolverPrice: 30000);
    $patient = makeBedDayCutoverPatient();
    $bed = makeBedDayCutoverBed(chargeableItemId: null);
    makeBedDayCutoverAdmission($patient->id, $bed->id);

    $candidate = $this->actingAs(makeBedDayCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(0.0)
        ->and($candidate['pricingStatus'])->toBe('missing_catalog_price')
        ->and($candidate['pricingSource'])->toBeNull();
});

it('generates one priced candidate per elapsed day, matching the pre-existing per-day loop', function (): void {
    $chargeableItemId = setUpBedDayCutoverPricing(legacyPrice: 20000, newResolverPrice: 30000);
    $patient = makeBedDayCutoverPatient();
    $bed = makeBedDayCutoverBed($chargeableItemId);
    AdmissionModel::query()->create([
        'admission_number' => 'ADM'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id, 'ward' => 'General Ward A', 'bed' => 'Bed 1', 'bed_resource_id' => $bed->id,
        'admitted_at' => now()->subHours(50)->toDateTimeString(), 'status' => 'admitted', 'admission_reason' => 'Observation',
    ]);

    $candidates = $this->actingAs(makeBedDayCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data');

    $bedDayCandidates = collect($candidates)->where('sourceWorkflowKind', 'admission_bed_day');
    expect($bedDayCandidates)->toHaveCount(3)
        ->and($bedDayCandidates->pluck('unitPrice')->unique()->all())->toBe([30000]);
});
