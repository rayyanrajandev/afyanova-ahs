<?php

use App\Models\User;
use App\Modules\Pos\Infrastructure\Models\PosRegisterModel;
use App\Modules\Pos\Infrastructure\Models\PosRegisterSessionModel;
use App\Modules\Pos\Infrastructure\Models\PosSaleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePosUser(array $permissions = []): User
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
function seedPosScope(int $userId): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TZ-POS',
        'name' => 'Tanzania POS Network',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'DAR-POS',
        'name' => 'Dar POS Center',
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
            'X-Tenant-Code' => 'TZ-POS',
            'X-Facility-Code' => 'DAR-POS',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function createPosRegister(User $user, array $scope, array $overrides = []): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/registers', array_merge([
            'registerCode' => 'REG-'.strtoupper(Str::random(6)),
            'registerName' => 'Main Counter',
            'location' => 'Ground Floor Cashier',
            'defaultCurrencyCode' => 'TZS',
            'notes' => 'Primary cashier desk',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

/**
 * @return array<string, mixed>
 */
function openPosSession(User $user, array $scope, string $registerId, array $overrides = []): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/registers/{$registerId}/sessions", array_merge([
            'openingCashAmount' => 100,
            'openingNote' => 'Opening float counted and verified.',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

it('creates registers under resolved scope and lists them with operational metadata', function (): void {
    $user = makePosUser([
        'pos.registers.read',
        'pos.registers.manage',
    ]);
    $scope = seedPosScope($user->id);

    $register = createPosRegister($user, $scope, [
        'registerCode' => 'FRONT-01',
        'registerName' => 'Front Desk POS',
    ]);

    expect($register['tenantId'])->toBe($scope['tenantId']);
    expect($register['facilityId'])->toBe($scope['facilityId']);
    expect($register['status'])->toBe('active');
    expect($register['currentOpenSession'])->toBeNull();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/registers?q=Front')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $register['id'])
        ->assertJsonPath('data.0.registerCode', 'FRONT-01');

    $stored = PosRegisterModel::query()->findOrFail($register['id']);
    expect($stored->tenant_id)->toBe($scope['tenantId']);
    expect($stored->facility_id)->toBe($scope['facilityId']);
});

it('opens one cashier session per register and blocks duplicate open sessions', function (): void {
    $user = makePosUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
    ]);
    $scope = seedPosScope($user->id);
    $register = createPosRegister($user, $scope);

    $session = openPosSession($user, $scope, (string) $register['id'], [
        'openingCashAmount' => 250,
    ]);

    expect($session['status'])->toBe('open');
    expect((float) $session['openingCashAmount'])->toBe(250.0);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/registers/{$register['id']}/sessions", [
            'openingCashAmount' => 100,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['registerId']);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/sessions?status=open')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $session['id']);
});

it('captures a paid sale, calculates change correctly, and closes the session with balanced totals', function (): void {
    $user = makePosUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
    ]);
    $scope = seedPosScope($user->id);
    $register = createPosRegister($user, $scope, [
        'registerCode' => 'PHA-OTC-01',
        'registerName' => 'Pharmacy OTC Counter',
    ]);
    $session = openPosSession($user, $scope, (string) $register['id'], [
        'openingCashAmount' => 100,
    ]);

    $sale = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/sales', [
            'registerId' => $register['id'],
            'saleChannel' => 'general_retail',
            'customerType' => 'anonymous',
            'customerName' => 'Walk-in customer',
            'lineItems' => [
                [
                    'itemType' => 'manual',
                    'itemCode' => 'MED-001',
                    'itemName' => 'Pain Relief Tabs',
                    'quantity' => 2,
                    'unitPrice' => 15,
                    'discountAmount' => 0,
                    'taxAmount' => 0,
                ],
                [
                    'itemType' => 'manual',
                    'itemCode' => 'MED-002',
                    'itemName' => 'Oral Rehydration Salts',
                    'quantity' => 1,
                    'unitPrice' => 10,
                    'discountAmount' => 0,
                    'taxAmount' => 0,
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 50,
                    'paymentReference' => 'CASH-DRAWER-001',
                ],
            ],
        ])
        ->assertCreated()
        ->json('data');

    expect($sale['registerSessionId'])->toBe($session['id']);
    expect((float) $sale['totalAmount'])->toBe(40.0);
    expect((float) $sale['paidAmount'])->toBe(40.0);
    expect((float) $sale['changeAmount'])->toBe(10.0);
    expect((float) $sale['payments'][0]['amountApplied'])->toBe(40.0);
    expect((float) $sale['payments'][0]['changeGiven'])->toBe(10.0);

    $closed = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->patchJson("/api/v1/pos/sessions/{$session['id']}/close", [
            'closingCashAmount' => 140,
            'closingNote' => 'Drawer counted and handed over clean.',
        ])
        ->assertOk()
        ->json('data');

    expect($closed['status'])->toBe('closed');
    expect((float) $closed['expectedCashAmount'])->toBe(140.0);
    expect((float) $closed['discrepancyAmount'])->toBe(0.0);
    expect((float) $closed['grossSalesAmount'])->toBe(40.0);
    expect((float) $closed['cashNetSalesAmount'])->toBe(40.0);
    expect((int) $closed['saleCount'])->toBe(1);

    $storedSale = PosSaleModel::query()->findOrFail($sale['id']);
    expect($storedSale->tenant_id)->toBe($scope['tenantId']);
    expect($storedSale->facility_id)->toBe($scope['facilityId']);

    $storedSession = PosRegisterSessionModel::query()->findOrFail($session['id']);
    expect($storedSession->status)->toBe('closed');
    expect((float) $storedSession->expected_cash_amount)->toBe(140.0);
});

it('returns a live closeout preview for an open cashier session before drawer close', function (): void {
    $user = makePosUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
    ]);
    $scope = seedPosScope($user->id);
    $register = createPosRegister($user, $scope, [
        'registerCode' => 'PREVIEW-01',
        'registerName' => 'Preview Register',
    ]);
    $session = openPosSession($user, $scope, (string) $register['id'], [
        'openingCashAmount' => 100,
    ]);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/sales', [
            'registerId' => $register['id'],
            'saleChannel' => 'general_retail',
            'customerType' => 'anonymous',
            'lineItems' => [
                [
                    'itemType' => 'manual',
                    'itemCode' => 'PREVIEW-ITEM-1',
                    'itemName' => 'Preview Basket',
                    'quantity' => 1,
                    'unitPrice' => 40,
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 50,
                ],
            ],
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/sessions/'.$session['id'])
        ->assertOk()
        ->assertJsonPath('data.id', $session['id'])
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.closeoutPreview.expectedCashAmount', 140)
        ->assertJsonPath('data.closeoutPreview.cashNetSalesAmount', 40)
        ->assertJsonPath('data.closeoutPreview.saleCount', 1)
        ->assertJsonPath('data.closeoutPreview.refundCount', 0)
        ->assertJsonPath('data.closeoutPreview.voidCount', 0);
});

it('rejects non cash overpayment that would create an invalid retail settlement', function (): void {
    $user = makePosUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.manage',
        'pos.sales.create',
    ]);
    $scope = seedPosScope($user->id);
    $register = createPosRegister($user, $scope);
    openPosSession($user, $scope, (string) $register['id']);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/sales', [
            'registerId' => $register['id'],
            'lineItems' => [
                [
                    'itemName' => 'Retail Vitamin Pack',
                    'quantity' => 1,
                    'unitPrice' => 25,
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'card',
                    'amount' => 40,
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payments.0.amount']);
});

it('denies register creation without POS permissions', function (): void {
    $user = makePosUser();
    $scope = seedPosScope($user->id);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/registers', [
            'registerCode' => 'BLOCKED-01',
            'registerName' => 'Blocked Register',
        ])
        ->assertForbidden();
});
