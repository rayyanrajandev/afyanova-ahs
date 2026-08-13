<?php

use App\Models\User;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Billing\Infrastructure\Models\PricingEngineShadowDiffModel;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Clinical Procedure and Theatre both have their legacy pricing paths fully
 * removed (PricingEngine_Migration_Plan.md Phase 5), so both are tested
 * unconditionally below, the same way Laboratory/Radiology are.
 */
function makeCPTCutoverUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('billing.invoices.create');
    $user->givePermissionTo('billing.invoices.read');

    return $user;
}

function makeCPTCutoverPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'CPT', 'last_name' => 'Cutover', 'gender' => 'female',
        'date_of_birth' => '1992-01-01', 'phone' => '+255700000055',
        'country_code' => 'TZ', 'status' => 'active',
    ]);
}

function setUpCPTCutover(string $catalogType, string $code, float $legacyPrice, float $newPrice): ClinicalCatalogItemModel
{
    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'catalog_type' => $catalogType, 'code' => $code, 'name' => 'Test Procedure', 'unit' => 'procedure', 'status' => 'active',
    ]);

    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->id = $catalogItem->id;
    $chargeableItem->fill(['catalog_type' => $catalogType, 'charge_model' => 'flat', 'code' => $code, 'name' => 'Test Procedure', 'status' => 'active']);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $catalogItem->id, 'currency_code' => 'TZS', 'unit_price' => $newPrice, 'status' => 'active',
    ]);

    BillingServiceCatalogItemModel::query()->create([
        'service_code' => $code, 'service_name' => 'Test Procedure', 'service_type' => $catalogType === 'clinical_procedure' ? 'procedure' : 'theatre',
        'unit' => 'procedure', 'base_price' => $legacyPrice, 'currency_code' => 'TZS', 'effective_from' => now()->subDay(), 'status' => 'active',
    ]);

    return $catalogItem;
}

it('prices clinical procedure orders via the chargeable item, ignoring any legacy tariff', function (): void {
    // A legacy tariff for the same code, deliberately present (via
    // setUpCPTCutover), to prove it's genuinely never consulted anymore.
    $catalogItem = setUpCPTCutover('clinical_procedure', 'PROC-TEST-001', legacyPrice: 40000, newPrice: 45000);
    $patient = makeCPTCutoverPatient();
    ClinicalProcedureOrderModel::query()->create([
        'order_number' => 'PROC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'clinical_procedure_catalog_item_id' => $catalogItem->id,
        'procedure_code' => 'PROC-TEST-001',
        'procedure_description' => 'Test Procedure',
        'completed_at' => now()->subHour()->toDateTimeString(),
        'status' => 'completed',
    ]);

    $candidate = $this->actingAs(makeCPTCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(45000.0)
        ->and($candidate['pricingStatus'])->toBe('priced')
        ->and($candidate['pricingSource'])->toBe('chargeable_item');
});

it('does not dispatch a shadow-diff comparison for clinical procedures, since there is no legacy price left to compare against', function (): void {
    $catalogItem = setUpCPTCutover('clinical_procedure', 'PROC-TEST-002', legacyPrice: 40000, newPrice: 45000);
    $patient = makeCPTCutoverPatient();
    ClinicalProcedureOrderModel::query()->create([
        'order_number' => 'PROC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'clinical_procedure_catalog_item_id' => $catalogItem->id,
        'procedure_code' => 'PROC-TEST-002',
        'procedure_description' => 'Test Procedure',
        'completed_at' => now()->subHour()->toDateTimeString(),
        'status' => 'completed',
    ]);

    $this->actingAs(makeCPTCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk();

    expect(PricingEngineShadowDiffModel::query()->count())->toBe(0);
});

it('reports missing_catalog_price for clinical procedures rather than falling back to a legacy tariff when there is no price book entry', function (): void {
    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'catalog_type' => 'clinical_procedure', 'code' => 'PROC-TEST-003', 'name' => 'Test Procedure', 'unit' => 'procedure', 'status' => 'active',
    ]);
    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->id = $catalogItem->id;
    $chargeableItem->fill(['catalog_type' => 'clinical_procedure', 'charge_model' => 'flat', 'code' => 'PROC-TEST-003', 'name' => 'Test Procedure', 'status' => 'active']);
    $chargeableItem->save();
    // Deliberately no PriceBookEntryModel row -- and a legacy tariff
    // present too, to prove it's genuinely never consulted as a fallback.
    BillingServiceCatalogItemModel::query()->create([
        'service_code' => 'PROC-TEST-003', 'service_name' => 'Test Procedure', 'service_type' => 'procedure',
        'unit' => 'procedure', 'base_price' => 40000, 'currency_code' => 'TZS', 'effective_from' => now()->subDay(), 'status' => 'active',
    ]);

    $patient = makeCPTCutoverPatient();
    ClinicalProcedureOrderModel::query()->create([
        'order_number' => 'PROC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'clinical_procedure_catalog_item_id' => $catalogItem->id,
        'procedure_code' => 'PROC-TEST-003',
        'procedure_description' => 'Test Procedure',
        'completed_at' => now()->subHour()->toDateTimeString(),
        'status' => 'completed',
    ]);

    $candidate = $this->actingAs(makeCPTCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(0.0)
        ->and($candidate['pricingStatus'])->toBe('missing_catalog_price')
        ->and($candidate['pricingSource'])->toBeNull();
});

it('prices theatre procedures via the chargeable item, ignoring any legacy tariff', function (): void {
    // A legacy tariff for the same code, deliberately present (via
    // setUpCPTCutover), to prove it's genuinely never consulted anymore.
    $catalogItem = setUpCPTCutover('theatre_procedure', 'THR-TEST-001', legacyPrice: 300000, newPrice: 350000);
    $patient = makeCPTCutoverPatient();
    TheatreProcedureModel::query()->create([
        'procedure_number' => 'THR'.now()->format('Ymd').strtoupper(Str::random(5)),
        'patient_id' => $patient->id,
        'procedure_type' => 'surgery',
        'procedure_name' => 'Test Surgery',
        'operating_clinician_user_id' => User::factory()->create()->id,
        'theatre_procedure_catalog_item_id' => $catalogItem->id,
        'theatre_room_name' => 'Theatre A',
        'scheduled_at' => now()->subHours(3)->toDateTimeString(),
        'completed_at' => now()->subHour()->toDateTimeString(),
        'status' => 'completed',
    ]);

    $candidate = $this->actingAs(makeCPTCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(350000.0)
        ->and($candidate['pricingStatus'])->toBe('priced')
        ->and($candidate['pricingSource'])->toBe('chargeable_item');
});

it('does not dispatch a shadow-diff comparison for theatre procedures, since there is no legacy price left to compare against', function (): void {
    $catalogItem = setUpCPTCutover('theatre_procedure', 'THR-TEST-002', legacyPrice: 300000, newPrice: 350000);
    $patient = makeCPTCutoverPatient();
    TheatreProcedureModel::query()->create([
        'procedure_number' => 'THR'.now()->format('Ymd').strtoupper(Str::random(5)),
        'patient_id' => $patient->id,
        'procedure_type' => 'surgery',
        'procedure_name' => 'Test Surgery',
        'operating_clinician_user_id' => User::factory()->create()->id,
        'theatre_procedure_catalog_item_id' => $catalogItem->id,
        'theatre_room_name' => 'Theatre A',
        'scheduled_at' => now()->subHours(3)->toDateTimeString(),
        'completed_at' => now()->subHour()->toDateTimeString(),
        'status' => 'completed',
    ]);

    $this->actingAs(makeCPTCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk();

    expect(PricingEngineShadowDiffModel::query()->count())->toBe(0);
});

it('reports missing_catalog_price for theatre procedures rather than falling back to a legacy tariff when there is no price book entry', function (): void {
    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'catalog_type' => 'theatre_procedure', 'code' => 'THR-TEST-003', 'name' => 'Test Procedure', 'unit' => 'procedure', 'status' => 'active',
    ]);
    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->id = $catalogItem->id;
    $chargeableItem->fill(['catalog_type' => 'theatre_procedure', 'charge_model' => 'flat', 'code' => 'THR-TEST-003', 'name' => 'Test Procedure', 'status' => 'active']);
    $chargeableItem->save();
    // Deliberately no PriceBookEntryModel row -- and a legacy tariff
    // present too, to prove it's genuinely never consulted as a fallback.
    BillingServiceCatalogItemModel::query()->create([
        'service_code' => 'THR-TEST-003', 'service_name' => 'Test Procedure', 'service_type' => 'theatre',
        'unit' => 'procedure', 'base_price' => 300000, 'currency_code' => 'TZS', 'effective_from' => now()->subDay(), 'status' => 'active',
    ]);

    $patient = makeCPTCutoverPatient();
    TheatreProcedureModel::query()->create([
        'procedure_number' => 'THR'.now()->format('Ymd').strtoupper(Str::random(5)),
        'patient_id' => $patient->id,
        'procedure_type' => 'surgery',
        'procedure_name' => 'Test Surgery',
        'operating_clinician_user_id' => User::factory()->create()->id,
        'theatre_procedure_catalog_item_id' => $catalogItem->id,
        'theatre_room_name' => 'Theatre A',
        'scheduled_at' => now()->subHours(3)->toDateTimeString(),
        'completed_at' => now()->subHour()->toDateTimeString(),
        'status' => 'completed',
    ]);

    $candidate = $this->actingAs(makeCPTCutoverUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(0.0)
        ->and($candidate['pricingStatus'])->toBe('missing_catalog_price')
        ->and($candidate['pricingSource'])->toBeNull();
});

