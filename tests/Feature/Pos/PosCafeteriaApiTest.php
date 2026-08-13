<?php

use App\Models\User;
use App\Modules\Pos\Infrastructure\Models\PosCafeteriaMenuItemModel;
use App\Modules\Pos\Infrastructure\Models\PosSaleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePosCafeteriaUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @return array{tenantId:string,facilityId:string,headers:array<string,string>}
 */
function seedPosCafeteriaScope(int $userId): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TZ-CAF',
        'name' => 'Tanzania Cafeteria Network',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'DAR-CAF',
        'name' => 'Dar Cafeteria Center',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'cashier',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'tenantId' => $tenantId,
        'facilityId' => $facilityId,
        'headers' => [
            'X-Tenant-Code' => 'TZ-CAF',
            'X-Facility-Code' => 'DAR-CAF',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function createPosCafeteriaRegister(User $user, array $scope, array $overrides = []): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/registers', array_merge([
            'registerCode' => 'CAF-'.strtoupper(Str::random(6)),
            'registerName' => 'Cafeteria Counter',
            'location' => 'Food court',
            'defaultCurrencyCode' => 'TZS',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

function openPosCafeteriaSession(User $user, array $scope, string $registerId): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/registers/{$registerId}/sessions", [
            'openingCashAmount' => 100,
            'openingNote' => 'Cafeteria shift opened.',
        ])
        ->assertCreated()
        ->json('data');
}

function createCafeteriaMenuItemFixture(array $scope, array $overrides = []): PosCafeteriaMenuItemModel
{
    return PosCafeteriaMenuItemModel::query()->create(array_merge([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'item_code' => 'CAF-'.strtoupper(Str::random(6)),
        'item_name' => 'Fresh Juice',
        'category' => 'beverages',
        'unit_label' => 'cup',
        'unit_price' => 3000,
        'tax_rate_percent' => 0,
        'status' => 'active',
        'status_reason' => null,
        'sort_order' => 10,
        'description' => 'Default cafeteria item',
        'metadata' => ['station' => 'main_bar'],
    ], $overrides));
}

it('creates, updates, and lists cafeteria menu items under the resolved scope', function (): void {
    $user = makePosCafeteriaUser([
        'pos.cafeteria.read',
        'pos.cafeteria.manage-catalog',
    ]);
    $scope = seedPosCafeteriaScope($user->id);

    $created = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/cafeteria/catalog', [
            'itemCode' => 'CAF-TEA',
            'itemName' => 'Masala Tea',
            'category' => 'beverages',
            'unitLabel' => 'cup',
            'unitPrice' => 2000,
            'taxRatePercent' => 0,
            'sortOrder' => 1,
            'description' => 'Hot spiced tea for staff and visitors.',
        ])
        ->assertCreated()
        ->json('data');

    expect($created['tenantId'])->toBe($scope['tenantId']);
    expect($created['facilityId'])->toBe($scope['facilityId']);
    expect($created['itemCode'])->toBe('CAF-TEA');
    expect($created['status'])->toBe('active');

    $updated = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->patchJson("/api/v1/pos/cafeteria/catalog/{$created['id']}", [
            'unitPrice' => 2500,
            'status' => 'inactive',
            'statusReason' => 'Temporarily unavailable this shift.',
        ])
        ->assertOk()
        ->json('data');

    expect((float) $updated['unitPrice'])->toBe(2500.0);
    expect($updated['status'])->toBe('inactive');
    expect($updated['statusReason'])->toBe('Temporarily unavailable this shift.');

    createCafeteriaMenuItemFixture($scope, [
        'item_code' => 'CAF-SNACK',
        'item_name' => 'Veg Samosa',
        'category' => 'snacks',
        'unit_price' => 1500,
        'sort_order' => 2,
    ]);

    $rows = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/cafeteria/catalog?status=active&category=snacks&perPage=10')
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['itemCode'])->toBe('CAF-SNACK');
    expect($rows[0]['category'])->toBe('snacks');
});

it('creates a cafeteria sale through the shared pos engine and computes tax plus change correctly', function (): void {
    $user = makePosCafeteriaUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
        'pos.cafeteria.read',
        'pos.cafeteria.create',
    ]);
    $scope = seedPosCafeteriaScope($user->id);
    $register = createPosCafeteriaRegister($user, $scope);
    openPosCafeteriaSession($user, $scope, (string) $register['id']);

    $tea = createCafeteriaMenuItemFixture($scope, [
        'item_code' => 'CAF-TEA',
        'item_name' => 'Masala Tea',
        'category' => 'beverages',
        'unit_label' => 'cup',
        'unit_price' => 2000,
        'tax_rate_percent' => 0,
        'sort_order' => 1,
    ]);
    $sandwich = createCafeteriaMenuItemFixture($scope, [
        'item_code' => 'CAF-SAND',
        'item_name' => 'Grilled Sandwich',
        'category' => 'meals',
        'unit_label' => 'plate',
        'unit_price' => 5000,
        'tax_rate_percent' => 10,
        'sort_order' => 2,
    ]);

    $sale = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/cafeteria/sales', [
            'registerId' => $register['id'],
            'customerName' => 'Visitor Counter Sale',
            'items' => [
                [
                    'menuItemId' => $tea->id,
                    'quantity' => 2,
                ],
                [
                    'menuItemId' => $sandwich->id,
                    'quantity' => 1,
                    'notes' => 'Cut in half',
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 10000,
                    'paymentReference' => 'CAF-CASH-001',
                ],
            ],
        ])
        ->assertCreated()
        ->json('data');

    expect($sale['saleChannel'])->toBe('cafeteria');
    expect((float) $sale['subtotalAmount'])->toBe(9000.0);
    expect((float) $sale['taxAmount'])->toBe(500.0);
    expect((float) $sale['totalAmount'])->toBe(9500.0);
    expect((float) $sale['changeAmount'])->toBe(500.0);
    expect($sale['lineItems'][0]['itemType'])->toBe('cafeteria_item');
    expect($sale['lineItems'][0]['itemReference'])->toBe($tea->id);
    expect($sale['lineItems'][1]['metadata']['cafeteriaMenuItemId'] ?? null)->toBe($sandwich->id);
    expect((float) ($sale['lineItems'][1]['metadata']['taxRatePercent'] ?? 0))->toBe(10.0);

    $storedSale = PosSaleModel::query()->findOrFail($sale['id']);
    expect($storedSale->sale_channel)->toBe('cafeteria');
    expect($storedSale->tenant_id)->toBe($scope['tenantId']);
    expect($storedSale->facility_id)->toBe($scope['facilityId']);
});

it('rejects inactive cafeteria menu items from checkout and leaves pos sales untouched', function (): void {
    $user = makePosCafeteriaUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.create',
        'pos.cafeteria.create',
    ]);
    $scope = seedPosCafeteriaScope($user->id);
    $register = createPosCafeteriaRegister($user, $scope);
    openPosCafeteriaSession($user, $scope, (string) $register['id']);

    $inactiveItem = createCafeteriaMenuItemFixture($scope, [
        'item_code' => 'CAF-SOUP',
        'item_name' => 'Pumpkin Soup',
        'category' => 'meals',
        'status' => 'inactive',
        'status_reason' => 'Kitchen closed for restock.',
    ]);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/cafeteria/sales', [
            'registerId' => $register['id'],
            'items' => [
                [
                    'menuItemId' => $inactiveItem->id,
                    'quantity' => 1,
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 3000,
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.menuItemId']);

    expect(PosSaleModel::query()->count())->toBe(0);
});
