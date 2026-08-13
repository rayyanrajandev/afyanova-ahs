<?php

use App\Models\User;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Modules\Billing\Infrastructure\Models\ConsultationMappingModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * PricingEngine_Migration_Plan.md Phase 5: Consultation is the first domain
 * with its legacy pricing path fully removed -- chargeable_item_id is now
 * required on every consultation mapping, billing_service_catalog_item_id
 * is legacy-optional (nullable, not touched by these routes anymore).
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureFacilitySubscriptionEntitlement::class);
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
});

function makeConsultationMappingUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function makeConsultationMappingChargeableItem(string $code = 'CONSULT-CO-OPD'): ChargeableItemModel
{
    $item = new ChargeableItemModel();
    $item->fill([
        'catalog_type' => 'consultation',
        'charge_model' => 'flat',
        'code' => $code,
        'name' => 'Clinical Officer Consultation - OPD',
        'status' => 'active',
    ]);
    $item->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $item->id,
        'currency_code' => 'TZS',
        'unit_price' => 12000,
        'status' => 'active',
    ]);

    return $item;
}

it('creates lists updates and deletes a consultation mapping through loaded routes', function (): void {
    $user = makeConsultationMappingUser([
        'billing.consultation-mappings.read',
        'billing.consultation-mappings.manage',
    ]);
    $chargeableItem = makeConsultationMappingChargeableItem();

    $mapping = $this->actingAs($user)
        ->postJson('/api/v1/consultation-mappings', [
            'chargeable_item_id' => $chargeableItem->id,
            'clinician_tier' => 'CO',
            'department' => 'Outpatient Department (OPD)',
        ])
        ->assertCreated()
        ->assertJsonPath('data.clinician_tier', 'CO')
        ->assertJsonPath('data.department', 'Outpatient Department (OPD)')
        ->assertJsonPath('data.chargeable_item_id', $chargeableItem->id)
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/consultation-mappings')
        ->assertOk()
        ->assertJsonPath('data.0.id', $mapping['id']);

    $otherChargeableItem = makeConsultationMappingChargeableItem('CONSULT-CO-OPD-2');

    $this->actingAs($user)
        ->patchJson("/api/v1/consultation-mappings/{$mapping['id']}", [
            'chargeable_item_id' => $otherChargeableItem->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.chargeable_item_id', $otherChargeableItem->id)
        ->assertJsonPath('data.department', 'Outpatient Department (OPD)');

    $this->actingAs($user)
        ->deleteJson("/api/v1/consultation-mappings/{$mapping['id']}")
        ->assertOk();

    expect(ConsultationMappingModel::query()->find($mapping['id']))->toBeNull();
});

it('rejects creating a mapping without a chargeable item', function (): void {
    $user = makeConsultationMappingUser([
        'billing.consultation-mappings.read',
        'billing.consultation-mappings.manage',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/consultation-mappings', [
            'clinician_tier' => 'CO',
            'department' => 'Outpatient Department (OPD)',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['chargeable_item_id']);
});

it('rejects a duplicate clinician tier and department mapping', function (): void {
    $user = makeConsultationMappingUser([
        'billing.consultation-mappings.read',
        'billing.consultation-mappings.manage',
    ]);
    $chargeableItem = makeConsultationMappingChargeableItem();

    ConsultationMappingModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id,
        'clinician_tier' => 'CO',
        'department' => 'Outpatient Department (OPD)',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/consultation-mappings', [
            'chargeable_item_id' => $chargeableItem->id,
            'clinician_tier' => 'CO',
            'department' => 'Outpatient Department (OPD)',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['department']);
});

it('rejects an unknown clinician tier', function (): void {
    $user = makeConsultationMappingUser([
        'billing.consultation-mappings.read',
        'billing.consultation-mappings.manage',
    ]);
    $chargeableItem = makeConsultationMappingChargeableItem();

    $this->actingAs($user)
        ->postJson('/api/v1/consultation-mappings', [
            'chargeable_item_id' => $chargeableItem->id,
            'clinician_tier' => 'NURSE',
            'department' => 'Outpatient Department (OPD)',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['clinician_tier']);
});

it('denies consultation mapping reads and writes without permission', function (): void {
    $user = makeConsultationMappingUser();
    $chargeableItem = makeConsultationMappingChargeableItem();

    $this->actingAs($user)
        ->getJson('/api/v1/consultation-mappings')
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson('/api/v1/consultation-mappings', [
            'chargeable_item_id' => $chargeableItem->id,
            'clinician_tier' => 'CO',
            'department' => 'Outpatient Department (OPD)',
        ])
        ->assertForbidden();
});
