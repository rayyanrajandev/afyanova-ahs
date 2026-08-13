<?php

use App\Models\User;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Regression guard for the claim that observation-room billing needed zero
 * new backend pricing code: charge-capture pricing keys off
 * facility_resources.chargeable_item_id regardless of resource_type, so an
 * observation_room-typed resource should price identically to a ward_bed
 * one via the same bed_day catalog mechanism (see BedDayPricingCutoverTest).
 */
uses(RefreshDatabase::class);

function makeObservationRoomPricingUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('billing.invoices.create');
    $user->givePermissionTo('billing.invoices.read');

    return $user;
}

function makeObservationRoomPricingPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'ObsRoom', 'last_name' => 'Pricing', 'gender' => 'female',
        'date_of_birth' => '1990-01-01', 'phone' => '+255700000044', 'country_code' => 'TZ', 'status' => 'active',
    ]);
}

function makeObservationRoomPricingChargeableItem(float $price): string
{
    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->fill([
        'catalog_type' => 'bed_day', 'charge_model' => 'flat',
        'code' => 'OBS-ROOM-FEMALE', 'name' => 'Observation Room - Female Day Rate', 'status' => 'active',
    ]);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id, 'currency_code' => 'TZS', 'unit_price' => $price, 'status' => 'active',
    ]);

    return $chargeableItem->id;
}

function makeObservationRoomPricingRoom(?string $chargeableItemId): FacilityResourceModel
{
    return FacilityResourceModel::query()->create([
        'resource_type' => 'observation_room', 'code' => 'OBS'.strtoupper(Str::random(6)),
        'name' => 'Observation Room - Female 1', 'ward_name' => 'Observation Room', 'bed_number' => 'F-01',
        'gender_restriction' => 'female', 'status' => 'active', 'chargeable_item_id' => $chargeableItemId,
    ]);
}

it('prices an observation-room admission via its chargeable item, same as a ward-bed', function (): void {
    $chargeableItemId = makeObservationRoomPricingChargeableItem(25000);
    $patient = makeObservationRoomPricingPatient();
    $room = makeObservationRoomPricingRoom($chargeableItemId);

    AdmissionModel::query()->create([
        'admission_number' => 'ADM'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id, 'ward' => 'Observation Room', 'bed' => 'F-01', 'bed_resource_id' => $room->id,
        'admitted_at' => now()->subHours(2)->toDateTimeString(), 'status' => 'admitted', 'admission_reason' => 'Observation',
    ]);

    $candidate = $this->actingAs(makeObservationRoomPricingUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(25000.0)
        ->and($candidate['pricingStatus'])->toBe('priced')
        ->and($candidate['pricingSource'])->toBe('chargeable_item');
});

it('reports missing_catalog_price for an observation room with no chargeable_item assigned', function (): void {
    $patient = makeObservationRoomPricingPatient();
    $room = makeObservationRoomPricingRoom(null);

    AdmissionModel::query()->create([
        'admission_number' => 'ADM'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id, 'ward' => 'Observation Room', 'bed' => 'F-01', 'bed_resource_id' => $room->id,
        'admitted_at' => now()->subHours(2)->toDateTimeString(), 'status' => 'admitted', 'admission_reason' => 'Observation',
    ]);

    $candidate = $this->actingAs(makeObservationRoomPricingUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(0.0)
        ->and($candidate['pricingStatus'])->toBe('missing_catalog_price')
        ->and($candidate['pricingSource'])->toBeNull();
});
