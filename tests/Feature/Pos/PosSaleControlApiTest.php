<?php

use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Pos\Infrastructure\Models\PosSaleAdjustmentModel;
use App\Modules\Pos\Infrastructure\Models\PosSaleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePosControlUser(array $permissions = []): User
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
function seedPosControlScope(int $userId): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TZ-PSC',
        'name' => 'Tanzania POS Control Network',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'DAR-PSC',
        'name' => 'Dar POS Control Center',
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
            'X-Tenant-Code' => 'TZ-PSC',
            'X-Facility-Code' => 'DAR-PSC',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function createPosControlRegister(User $user, array $scope, array $overrides = []): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/registers', array_merge([
            'registerCode' => 'PSC-'.strtoupper(Str::random(6)),
            'registerName' => 'POS Control Register',
            'location' => 'Retail Counter',
            'defaultCurrencyCode' => 'TZS',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

/**
 * @return array<string, mixed>
 */
function openPosControlSession(User $user, array $scope, string $registerId, float $openingCashAmount = 100): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/registers/{$registerId}/sessions", [
            'openingCashAmount' => $openingCashAmount,
            'openingNote' => 'Shift opened for POS control testing.',
        ])
        ->assertCreated()
        ->json('data');
}

function createPosControlCatalogItem(array $overrides = []): ClinicalCatalogItemModel
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
        'description' => 'POS control OTC medicine fixture',
        'metadata' => [
            'dosageForm' => 'tablet',
            'strength' => '500 mg',
            'retailPrice' => 15,
        ],
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function createPosControlInventoryItem(array $overrides = []): InventoryItemModel
{
    $attributes = array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'item_code' => 'ATC:N02BE01',
        'item_name' => 'Paracetamol 500mg',
        'category' => 'pharmaceutical',
        'unit' => 'tablet',
        'current_stock' => 20,
        'reorder_level' => 5,
        'max_stock_level' => 100,
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
            'description' => 'Auto-linked POS control inventory test fixture',
            'metadata' => [
                'dosageForm' => 'tablet',
                'strength' => '500 mg',
                'retailPrice' => 15,
            ],
            'status' => 'active',
            'status_reason' => null,
        ],
    )->id;

    return InventoryItemModel::query()->create($attributes);
}

/**
 * @return array<string, mixed>
 */
function createPosControlPharmacySale(
    User $user,
    array $scope,
    string $registerId,
    string $catalogItemId,
    int|float $quantity,
    int|float $paymentAmount
): array {
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/pharmacy-otc/sales', [
            'registerId' => $registerId,
            'customerName' => 'Walk-in pharmacy customer',
            'items' => [
                [
                    'catalogItemId' => $catalogItemId,
                    'quantity' => $quantity,
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => $paymentAmount,
                ],
            ],
        ])
        ->assertCreated()
        ->json('data');
}

/**
 * @return array<string, mixed>
 */
function createPosControlRetailSale(
    User $user,
    array $scope,
    string $registerId,
    int|float $unitPrice,
    int|float $paymentAmount
): array {
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/sales', [
            'registerId' => $registerId,
            'saleChannel' => 'general_retail',
            'customerType' => 'anonymous',
            'lineItems' => [
                [
                    'itemType' => 'manual',
                    'itemCode' => 'RET-001',
                    'itemName' => 'Retail OTC Basket',
                    'quantity' => 1,
                    'unitPrice' => $unitPrice,
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => $paymentAmount,
                ],
            ],
        ])
        ->assertCreated()
        ->json('data');
}

it('voids a same-session pharmacy otc sale, records the adjustment, restores stock, and closes with balanced cash', function (): void {
    $user = makePosControlUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
        'pos.sales.void',
        'pos.pharmacy-otc.create',
    ]);
    $scope = seedPosControlScope($user->id);
    $register = createPosControlRegister($user, $scope, [
        'registerCode' => 'VOID-OTC-01',
        'registerName' => 'Void OTC Register',
    ]);
    $session = openPosControlSession($user, $scope, (string) $register['id'], 100);

    $catalogItem = createPosControlCatalogItem();
    $inventoryItem = createPosControlInventoryItem([
        'current_stock' => 20,
    ]);

    $sale = createPosControlPharmacySale($user, $scope, (string) $register['id'], $catalogItem->id, 2, 40);

    $inventoryItem->refresh();
    expect((float) $inventoryItem->current_stock)->toBe(18.0);

    $voidedSale = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/sales/{$sale['id']}/void", [
            'reasonCode' => 'error_correction',
            'note' => 'Cashier entered the wrong OTC basket.',
        ])
        ->assertOk()
        ->json('data');

    expect($voidedSale['status'])->toBe('voided');
    expect($voidedSale['adjustments'])->toHaveCount(1);
    expect($voidedSale['adjustments'][0]['adjustmentType'])->toBe('void');
    expect($voidedSale['adjustments'][0]['registerSessionId'])->toBe($session['id']);

    $inventoryItem->refresh();
    expect((float) $inventoryItem->current_stock)->toBe(20.0);

    $restockMovement = InventoryStockMovementModel::query()
        ->where('reason', 'pos_sale_void_restock')
        ->latest('created_at')
        ->first();
    expect($restockMovement)->not->toBeNull();
    expect((float) $restockMovement?->quantity)->toBe(2.0);

    $closedSession = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->patchJson("/api/v1/pos/sessions/{$session['id']}/close", [
            'closingCashAmount' => 100,
            'closingNote' => 'Void completed before drawer close.',
        ])
        ->assertOk()
        ->json('data');

    expect((float) $closedSession['cashNetSalesAmount'])->toBe(30.0);
    expect((float) $closedSession['cashAdjustmentAmount'])->toBe(30.0);
    expect((float) $closedSession['expectedCashAmount'])->toBe(100.0);
    expect((float) $closedSession['adjustmentAmount'])->toBe(30.0);
    expect((int) $closedSession['voidCount'])->toBe(1);
    expect((int) $closedSession['refundCount'])->toBe(0);
});

it('restores FEFO batch quantities when a pharmacy otc sale is voided', function (): void {
    $user = makePosControlUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
        'pos.sales.void',
        'pos.pharmacy-otc.create',
    ]);
    $scope = seedPosControlScope($user->id);
    $register = createPosControlRegister($user, $scope, [
        'registerCode' => 'VOID-FEFO-01',
        'registerName' => 'Void FEFO Register',
    ]);
    openPosControlSession($user, $scope, (string) $register['id'], 100);

    $catalogItem = createPosControlCatalogItem([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
    ]);
    $inventoryItem = createPosControlInventoryItem([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'current_stock' => 20,
    ]);
    $earliestBatch = inventoryBatchRecord($inventoryItem->id, [
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'batch_number' => 'VOID-FEFO-001',
        'expiry_date' => now()->addDays(15)->toDateString(),
        'quantity' => 1,
    ]);
    $laterBatch = inventoryBatchRecord($inventoryItem->id, [
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'batch_number' => 'VOID-FEFO-002',
        'expiry_date' => now()->addDays(90)->toDateString(),
        'quantity' => 5,
    ]);

    $sale = createPosControlPharmacySale($user, $scope, (string) $register['id'], $catalogItem->id, 2, 40);

    expect((float) DB::table('inventory_batches')->where('id', $earliestBatch['id'])->value('quantity'))->toBe(0.0);
    expect((float) DB::table('inventory_batches')->where('id', $laterBatch['id'])->value('quantity'))->toBe(4.0);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/sales/{$sale['id']}/void", [
            'reasonCode' => 'error_correction',
            'note' => 'Restore tracked FEFO stock.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided');

    expect((float) DB::table('inventory_batches')->where('id', $earliestBatch['id'])->value('quantity'))->toBe(1.0);
    expect((float) DB::table('inventory_batches')->where('id', $laterBatch['id'])->value('quantity'))->toBe(5.0);

    $restockMovement = InventoryStockMovementModel::query()
        ->where('reason', 'pos_sale_void_restock')
        ->latest('created_at')
        ->first();

    expect($restockMovement?->metadata['batchMode'] ?? null)->toBe('tracked');
    expect($restockMovement?->metadata['batchAllocationCount'] ?? null)->toBe(2);
});

it('refunds a pharmacy otc sale through a later session and subtracts the payout from that drawer', function (): void {
    $user = makePosControlUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
        'pos.sales.refund',
        'pos.pharmacy-otc.create',
    ]);
    $scope = seedPosControlScope($user->id);
    $saleRegister = createPosControlRegister($user, $scope, [
        'registerCode' => 'SALE-OTC-01',
        'registerName' => 'Sale OTC Register',
    ]);
    $refundRegister = createPosControlRegister($user, $scope, [
        'registerCode' => 'REF-OTC-01',
        'registerName' => 'Refund OTC Register',
    ]);
    $saleSession = openPosControlSession($user, $scope, (string) $saleRegister['id'], 100);
    $refundSession = openPosControlSession($user, $scope, (string) $refundRegister['id'], 80);

    $catalogItem = createPosControlCatalogItem();
    $inventoryItem = createPosControlInventoryItem([
        'current_stock' => 20,
    ]);

    $sale = createPosControlPharmacySale($user, $scope, (string) $saleRegister['id'], $catalogItem->id, 2, 40);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->patchJson("/api/v1/pos/sessions/{$saleSession['id']}/close", [
            'closingCashAmount' => 130,
            'closingNote' => 'Original OTC drawer closed before return.',
        ])
        ->assertOk()
        ->assertJsonPath('data.expectedCashAmount', '130.00');

    $inventoryItem->refresh();
    expect((float) $inventoryItem->current_stock)->toBe(18.0);

    $refundedSale = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/sales/{$sale['id']}/refund", [
            'registerId' => $refundRegister['id'],
            'refundMethod' => 'cash',
            'refundReference' => 'REF-CASH-001',
            'reasonCode' => 'customer_return',
            'note' => 'Customer returned the sealed medicine pack.',
        ])
        ->assertOk()
        ->json('data');

    expect($refundedSale['status'])->toBe('refunded');
    expect($refundedSale['adjustments'])->toHaveCount(1);
    expect($refundedSale['adjustments'][0]['adjustmentType'])->toBe('refund');
    expect($refundedSale['adjustments'][0]['registerSessionId'])->toBe($refundSession['id']);
    expect($refundedSale['adjustments'][0]['paymentMethod'])->toBe('cash');

    $inventoryItem->refresh();
    expect((float) $inventoryItem->current_stock)->toBe(20.0);

    $refundClose = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->patchJson("/api/v1/pos/sessions/{$refundSession['id']}/close", [
            'closingCashAmount' => 50,
            'closingNote' => 'Refund payout counted in closeout.',
        ])
        ->assertOk()
        ->json('data');

    expect((float) $refundClose['cashNetSalesAmount'])->toBe(0.0);
    expect((float) $refundClose['cashAdjustmentAmount'])->toBe(30.0);
    expect((float) $refundClose['expectedCashAmount'])->toBe(50.0);
    expect((int) $refundClose['refundCount'])->toBe(1);
});

it('rejects voiding a sale after the original session has already been closed', function (): void {
    $user = makePosControlUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
        'pos.sales.void',
    ]);
    $scope = seedPosControlScope($user->id);
    $register = createPosControlRegister($user, $scope, [
        'registerCode' => 'VOID-LATE-01',
        'registerName' => 'Late Void Register',
    ]);
    $session = openPosControlSession($user, $scope, (string) $register['id'], 100);

    $sale = createPosControlRetailSale($user, $scope, (string) $register['id'], 25, 30);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->patchJson("/api/v1/pos/sessions/{$session['id']}/close", [
            'closingCashAmount' => 125,
            'closingNote' => 'Session closed before any correction.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/sales/{$sale['id']}/void", [
            'reasonCode' => 'duplicate_sale',
            'note' => 'Attempted after close.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['saleId']);

    $storedSale = PosSaleModel::query()->findOrFail($sale['id']);
    expect($storedSale->status)->toBe('completed');
    expect(PosSaleAdjustmentModel::query()->count())->toBe(0);
});

it('refunds a same-session cash sale without double counting the drawer cash at closeout', function (): void {
    $user = makePosControlUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
        'pos.sales.refund',
    ]);
    $scope = seedPosControlScope($user->id);
    $register = createPosControlRegister($user, $scope, [
        'registerCode' => 'REF-SAME-01',
        'registerName' => 'Same Session Refund Register',
    ]);
    $session = openPosControlSession($user, $scope, (string) $register['id'], 100);

    $sale = createPosControlRetailSale($user, $scope, (string) $register['id'], 40, 50);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/sales/{$sale['id']}/refund", [
            'registerId' => $register['id'],
            'refundMethod' => 'cash',
            'reasonCode' => 'customer_return',
            'note' => 'Same-session correction.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'refunded');

    $closedSession = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->patchJson("/api/v1/pos/sessions/{$session['id']}/close", [
            'closingCashAmount' => 100,
            'closingNote' => 'Refund reversed the same shift sale.',
        ])
        ->assertOk()
        ->json('data');

    expect((float) $closedSession['cashNetSalesAmount'])->toBe(40.0);
    expect((float) $closedSession['cashAdjustmentAmount'])->toBe(40.0);
    expect((float) $closedSession['expectedCashAmount'])->toBe(100.0);
    expect((int) $closedSession['refundCount'])->toBe(1);
});
