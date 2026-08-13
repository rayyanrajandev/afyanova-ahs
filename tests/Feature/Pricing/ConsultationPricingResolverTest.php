<?php

use App\Modules\Billing\Application\Support\ConsultationPricingResolver;
use App\Modules\Billing\Infrastructure\Models\ConsultationMappingModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * PricingEngine_Migration_Plan.md Phase 5: Consultation's legacy path is
 * gone, so this resolver is unconditional now -- no feature flag left to
 * gate it.
 */
uses(RefreshDatabase::class);

function makeLinkedConsultationMapping(float $chargeableItemPrice): ConsultationMappingModel
{
    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->fill(['catalog_type' => 'consultation', 'charge_model' => 'flat', 'code' => 'CONSULT-CO-GENERAL-OPD', 'name' => 'CO Consultation', 'status' => 'active']);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id, 'currency_code' => 'TZS', 'unit_price' => $chargeableItemPrice, 'status' => 'active',
    ]);

    return ConsultationMappingModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id,
        'clinician_tier' => 'CO',
        'department' => 'General OPD',
    ]);
}

it('returns a priced result when the mapping has a chargeable_item_id', function (): void {
    makeLinkedConsultationMapping(18000);

    $result = app(ConsultationPricingResolver::class)->resolveViaExplicitMapping(
        mapping: null, tier: 'CO', department: 'General OPD', quantity: 1.0,
        performedAt: null, tenantId: null, facilityId: null, currencyCode: 'TZS',
    );

    expect($result)->not->toBeNull()
        ->and($result['unitPrice'])->toBe(18000.0)
        ->and($result['pricingStatus'])->toBe('priced');
});

it('returns null when no mapping exists for that tier/department', function (): void {
    $result = app(ConsultationPricingResolver::class)->resolveViaExplicitMapping(
        mapping: null, tier: 'MD', department: 'Cardiology', quantity: 1.0,
        performedAt: null, tenantId: null, facilityId: null, currencyCode: 'TZS',
    );

    expect($result)->toBeNull();
});

it('returns null when a mapping exists but has no chargeable_item_id yet', function (): void {
    ConsultationMappingModel::query()->create([
        'clinician_tier' => 'AMO',
        'department' => 'General OPD',
    ]);

    $result = app(ConsultationPricingResolver::class)->resolveViaExplicitMapping(
        mapping: null, tier: 'AMO', department: 'General OPD', quantity: 1.0,
        performedAt: null, tenantId: null, facilityId: null, currencyCode: 'TZS',
    );

    expect($result)->toBeNull();
});

it('accepts a pre-fetched mapping instead of re-querying, for callers that already have one', function (): void {
    $mapping = makeLinkedConsultationMapping(18000);

    $result = app(ConsultationPricingResolver::class)->resolveViaExplicitMapping(
        mapping: $mapping, tier: 'CO', department: 'General OPD', quantity: 1.0,
        performedAt: null, tenantId: null, facilityId: null, currencyCode: 'TZS',
    );

    expect($result['unitPrice'])->toBe(18000.0);
});
