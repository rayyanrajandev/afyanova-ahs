<?php

use App\Models\User;
use App\Modules\Revenue\Application\UseCases\SearchChargeableItemsUseCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A cashier may not price a clinical order.
 *
 * The prescriber decides a patient needs 21 tablets and the pharmacist decides
 * what is actually dispensed. A cashier has no basis for either number, so a
 * counter-typed drug charge matches no prescription and reconciles against
 * nothing. Those charges belong to the order that requested them.
 */
function counterUser(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['cashier']['permissions'], 'FINANCE.CASHIER');
}

function catalogItem(string $code, string $catalogType): string
{
    $id = (string) Str::uuid();

    DB::table('chargeable_items')->insert([
        'id' => $id,
        'catalog_type' => $catalogType,
        'charge_model' => 'flat',
        'code' => $code,
        'name' => 'Test '.$code,
        'default_unit' => 'unit',
        'status' => 'active',
        'is_taxable' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('price_book_entries')->insert([
        'id' => (string) Str::uuid(),
        'chargeable_item_id' => $id,
        'currency_code' => 'TZS',
        'unit_price' => '1000.00',
        'tax_rate_percent' => 0,
        'is_taxable' => false,
        'tariff_version' => 1,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('keeps clinically ordered items out of the counter price list', function (string $catalogType): void {
    catalogItem('X-'.Str::upper(Str::random(6)), $catalogType);

    $codes = collect(app(SearchChargeableItemsUseCase::class)->execute()['data'])
        ->pluck('catalogType');

    expect($codes)->not->toContain($catalogType);
})->with(['formulary_item', 'lab_test', 'radiology_procedure', 'clinical_procedure']);

it('still offers what a counter legitimately sells', function (): void {
    catalogItem('CERT-FITNESS', 'consultation');

    $types = collect(app(SearchChargeableItemsUseCase::class)->execute()['data'])
        ->pluck('catalogType')
        ->unique();

    expect($types)->toContain('consultation');
});

it('refuses a clinically ordered item even when its id is posted directly', function (): void {
    // Hiding something in a picker is not a control while the endpoint still
    // accepts its id.
    $drugId = catalogItem('MED-AMOX-500', 'formulary_item');

    test()->actingAs(counterUser())
        ->postJson('/api/v1/cashier/charges', [
            'patientId' => (string) Str::uuid(),
            'chargeableItemId' => $drugId,
            'quantity' => 21,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'COUNTER_CHARGE_NOT_ALLOWED')
        ->assertJsonPath('catalogType', 'formulary_item');

    expect(DB::table('service_charges')->count())->toBe(0);
});
