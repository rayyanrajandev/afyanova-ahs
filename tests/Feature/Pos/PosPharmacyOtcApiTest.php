<?php

use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Pos\Infrastructure\Models\PosSaleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePosPharmacyUser(array $permissions = []): User
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
function seedPosPharmacyScope(int $userId): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TZ-OTC',
        'name' => 'Tanzania OTC Network',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'DAR-OTC',
        'name' => 'Dar OTC Center',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'pharmacist',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'tenantId' => $tenantId,
        'facilityId' => $facilityId,
        'headers' => [
            'X-Tenant-Code' => 'TZ-OTC',
            'X-Facility-Code' => 'DAR-OTC',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function createPosPharmacyRegister(User $user, array $scope, array $overrides = []): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/registers', array_merge([
            'registerCode' => 'PHA-'.strtoupper(Str::random(6)),
            'registerName' => 'Pharmacy OTC Counter',
            'location' => 'Pharmacy retail desk',
            'defaultCurrencyCode' => 'TZS',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

/**
 * @return array<string, mixed>
 */
function openPosPharmacySession(User $user, array $scope, string $registerId): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/registers/{$registerId}/sessions", [
            'openingCashAmount' => 100,
            'openingNote' => 'Opening float verified.',
        ])
        ->assertCreated()
        ->json('data');
}

function createPosApprovedMedicineCatalogItem(array $overrides = []): ClinicalCatalogItemModel
{
    return ClinicalCatalogItemModel::query()->create(array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'formulary_item',
        'code' => 'ATC:N02BE01',
        'name' => 'Paracetamol 500mg',
        'department_id' => null,
        'category' => 'analgesics',
        'unit' => 'tablet',
        'description' => 'Default OTC medicine fixture',
        'metadata' => [
            'dosageForm' => 'tablet',
            'strength' => '500 mg',
        ],
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function createPharmacyInventoryItem(array $overrides = []): InventoryItemModel
{
    $attributes = array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'item_code' => 'ATC:N02BE01',
        'item_name' => 'Paracetamol 500mg',
        'category' => 'pharmaceutical',
        'unit' => 'tablet',
        'current_stock' => 240,
        'reorder_level' => 80,
        'max_stock_level' => 600,
        'status' => 'active',
    ], $overrides);

    $catalogCategory = (string) ($overrides['category'] ?? 'analgesics');
    $attributes['category'] = 'pharmaceutical';
    $attributes['clinical_catalog_item_id'] = $attributes['clinical_catalog_item_id'] ?? ClinicalCatalogItemModel::query()->firstOrCreate(
        [
            'tenant_id' => $attributes['tenant_id'],
            'facility_id' => $attributes['facility_id'],
            'catalog_type' => 'formulary_item',
            'code' => $attributes['item_code'],
        ],
        [
            'name' => $attributes['item_name'],
            'department_id' => null,
            'category' => $catalogCategory,
            'unit' => $attributes['unit'],
            'description' => 'Auto-linked OTC inventory test fixture',
            'metadata' => [
                'dosageForm' => 'tablet',
                'strength' => '500 mg',
            ],
            'status' => 'active',
            'status_reason' => null,
        ],
    )->id;

    return InventoryItemModel::query()->create($attributes);
}

it('lists pharmacy otc approved medicines with stock context and otc eligibility flags', function (): void {
    $user = makePosPharmacyUser([
        'pos.pharmacy-otc.read',
    ]);
    $scope = seedPosPharmacyScope($user->id);

    createPosApprovedMedicineCatalogItem([
        'metadata' => [
            'dosageForm' => 'tablet',
            'strength' => '500 mg',
            'retailPrice' => 1500,
        ],
    ]);
    createPharmacyInventoryItem([
        'current_stock' => 240,
        'reorder_level' => 80,
    ]);

    createPosApprovedMedicineCatalogItem([
        'code' => 'ATC:J01DD04',
        'name' => 'Ceftriaxone 1g',
        'unit' => 'vial',
        'category' => 'antibiotics',
        'metadata' => [
            'dosageForm' => 'injection',
            'strength' => '1 g',
            'reviewMode' => 'policy_review_required',
        ],
    ]);
    createPharmacyInventoryItem([
        'item_code' => 'ATC:J01DD04',
        'item_name' => 'Ceftriaxone 1g',
        'unit' => 'vial',
        'category' => 'antibiotics',
        'current_stock' => 12,
        'reorder_level' => 4,
    ]);
    createPosApprovedMedicineCatalogItem([
        'code' => 'ATC:A11CC01',
        'name' => 'Vitamin D3',
        'category' => 'supplements',
        'metadata' => [
            'dosageForm' => 'tablet',
            'strength' => '1000 IU',
        ],
    ]);
    createPharmacyInventoryItem([
        'item_code' => 'ATC:A11CC01',
        'item_name' => 'Vitamin D3',
        'category' => 'supplements',
        'current_stock' => 0,
        'reorder_level' => 10,
    ]);
    createPosApprovedMedicineCatalogItem([
        'code' => 'ATC:B03BB01',
        'name' => 'Folic Acid',
        'category' => 'supplements',
        'metadata' => [
            'dosageForm' => 'tablet',
            'strength' => '5 mg',
        ],
    ]);

    $rows = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/pharmacy-otc/catalog?perPage=10&sortBy=name&sortDir=asc')
        ->assertOk()
        ->json('data');

    $paracetamol = collect($rows)->firstWhere('code', 'ATC:N02BE01');
    $ceftriaxone = collect($rows)->firstWhere('code', 'ATC:J01DD04');

    expect($paracetamol)->not->toBeNull();
    expect($paracetamol['otcEligible'])->toBeTrue();
    expect((float) $paracetamol['otcUnitPrice'])->toBe(1500.0);
    expect($paracetamol['inventoryItem']['stockState'])->toBe('healthy');
    expect((float) $paracetamol['inventoryItem']['currentStock'])->toBe(240.0);

    expect($ceftriaxone)->toBeNull();
    expect(collect($rows)->firstWhere('code', 'ATC:A11CC01'))->toBeNull();
    expect(collect($rows)->firstWhere('code', 'ATC:B03BB01'))->toBeNull();
});

it('hides pharmacy otc medicines when only expired or quarantined batches remain', function (): void {
    $user = makePosPharmacyUser([
        'pos.pharmacy-otc.read',
    ]);
    $scope = seedPosPharmacyScope($user->id);

    $catalogItem = createPosApprovedMedicineCatalogItem([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'code' => 'ATC:J01CA04',
        'name' => 'Amoxicillin 500mg',
        'category' => 'antibiotics',
        'unit' => 'capsule',
    ]);
    $inventoryItem = createPharmacyInventoryItem([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'item_code' => 'ATC:J01CA04',
        'item_name' => 'Amoxicillin 500mg',
        'category' => 'antibiotics',
        'unit' => 'capsule',
        'current_stock' => 12,
    ]);

    inventoryBatchRecord($inventoryItem->id, [
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'batch_number' => 'OTC-EXP-001',
        'expiry_date' => now()->subDay()->toDateString(),
        'quantity' => 8,
        'status' => 'available',
    ]);
    inventoryBatchRecord($inventoryItem->id, [
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'batch_number' => 'OTC-QUAR-001',
        'expiry_date' => now()->addDays(30)->toDateString(),
        'quantity' => 4,
        'status' => 'quarantined',
    ]);

    $rows = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/pharmacy-otc/catalog')
        ->assertOk()
        ->json('data');

    expect(collect($rows)->firstWhere('id', $catalogItem->id))->toBeNull();
});

it('creates a pharmacy otc sale and issues inventory stock in the same checkout flow', function (): void {
    $user = makePosPharmacyUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
        'pos.pharmacy-otc.read',
        'pos.pharmacy-otc.create',
    ]);
    $scope = seedPosPharmacyScope($user->id);
    $register = createPosPharmacyRegister($user, $scope);
    openPosPharmacySession($user, $scope, (string) $register['id']);

    $catalogItem = createPosApprovedMedicineCatalogItem([
        'metadata' => [
            'dosageForm' => 'tablet',
            'strength' => '500 mg',
            'retailPrice' => 15,
        ],
    ]);
    $inventoryItem = createPharmacyInventoryItem([
        'current_stock' => 20,
        'reorder_level' => 5,
    ]);

    $sale = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/pharmacy-otc/sales', [
            'registerId' => $register['id'],
            'customerName' => 'Walk-in pharmacy customer',
            'items' => [
                [
                    'catalogItemId' => $catalogItem->id,
                    'quantity' => 2,
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 50,
                    'paymentReference' => 'OTC-CASH-001',
                ],
            ],
        ])
        ->assertCreated()
        ->json('data');

    expect($sale['saleChannel'])->toBe('pharmacy_otc');
    expect((float) $sale['totalAmount'])->toBe(30.0);
    expect((float) $sale['changeAmount'])->toBe(20.0);
    expect($sale['lineItems'][0]['itemType'])->toBe('pharmacy_item');
    expect($sale['lineItems'][0]['itemReference'])->toBe($inventoryItem->id);
    expect($sale['lineItems'][0]['metadata']['approvedMedicineCatalogItemId'] ?? null)->toBe($catalogItem->id);
    expect($sale['lineItems'][0]['metadata']['priceSource'] ?? null)->toBe('catalog_metadata.retailPrice');

    $storedSale = PosSaleModel::query()->findOrFail($sale['id']);
    expect($storedSale->sale_channel)->toBe('pharmacy_otc');

    $inventoryItem->refresh();
    expect((float) $inventoryItem->current_stock)->toBe(18.0);

    $stockMovement = InventoryStockMovementModel::query()->latest('created_at')->first();
    expect($stockMovement)->not->toBeNull();
    expect($stockMovement?->reason)->toBe('pharmacy_otc_sale');
    expect((float) $stockMovement?->quantity)->toBe(2.0);
    expect($stockMovement?->metadata['pos_sale_id'] ?? null)->toBe($sale['id']);
});

it('rejects restricted approved medicines from pharmacy otc checkout', function (): void {
    $user = makePosPharmacyUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.create',
        'pos.pharmacy-otc.create',
    ]);
    $scope = seedPosPharmacyScope($user->id);
    $register = createPosPharmacyRegister($user, $scope);
    openPosPharmacySession($user, $scope, (string) $register['id']);

    $catalogItem = createPosApprovedMedicineCatalogItem([
        'code' => 'ATC:J01DD04',
        'name' => 'Ceftriaxone 1g',
        'unit' => 'vial',
        'category' => 'antibiotics',
        'metadata' => [
            'dosageForm' => 'injection',
            'strength' => '1 g',
            'reviewMode' => 'policy_review_required',
        ],
    ]);
    $inventoryItem = createPharmacyInventoryItem([
        'item_code' => 'ATC:J01DD04',
        'item_name' => 'Ceftriaxone 1g',
        'unit' => 'vial',
        'category' => 'antibiotics',
        'current_stock' => 10,
    ]);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/pharmacy-otc/sales', [
            'registerId' => $register['id'],
            'items' => [
                [
                    'catalogItemId' => $catalogItem->id,
                    'quantity' => 1,
                    'unitPrice' => 40,
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 40,
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.catalogItemId']);

    expect(PosSaleModel::query()->count())->toBe(0);

    $inventoryItem->refresh();
    expect((float) $inventoryItem->current_stock)->toBe(10.0);
});

it('rejects pharmacy otc quantity beyond available stock and leaves inventory unchanged', function (): void {
    $user = makePosPharmacyUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.create',
        'pos.pharmacy-otc.create',
    ]);
    $scope = seedPosPharmacyScope($user->id);
    $register = createPosPharmacyRegister($user, $scope);
    openPosPharmacySession($user, $scope, (string) $register['id']);

    $catalogItem = createPosApprovedMedicineCatalogItem([
        'metadata' => [
            'dosageForm' => 'tablet',
            'strength' => '500 mg',
        ],
    ]);
    $inventoryItem = createPharmacyInventoryItem([
        'current_stock' => 1,
        'reorder_level' => 1,
    ]);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/pharmacy-otc/sales', [
            'registerId' => $register['id'],
            'items' => [
                [
                    'catalogItemId' => $catalogItem->id,
                    'quantity' => 3,
                    'unitPrice' => 15,
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 45,
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.quantity']);

    expect(PosSaleModel::query()->count())->toBe(0);

    $inventoryItem->refresh();
    expect((float) $inventoryItem->current_stock)->toBe(1.0);
});
