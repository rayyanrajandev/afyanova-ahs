<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function makePosReceiptActor(array $permissions = []): User
{
    return makeUserWithRole($permissions);
}

/**
 * @return array{tenantId:string,facilityId:string,headers:array<string,string>}
 */
function seedPosReceiptScope(int $userId): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TZ-PSR',
        'name' => 'Tanzania POS Receipt Network',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'DAR-PSR',
        'name' => 'Dar POS Receipt Center',
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
            'X-Tenant-Code' => 'TZ-PSR',
            'X-Facility-Code' => 'DAR-PSR',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function createPosReceiptRegister(User $user, array $scope, array $overrides = []): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/registers', array_merge([
            'registerCode' => 'REC-'.strtoupper(Str::random(6)),
            'registerName' => 'Receipt Register',
            'location' => 'Front Cashier',
            'defaultCurrencyCode' => 'TZS',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

/**
 * @return array<string, mixed>
 */
function openPosReceiptSession(User $user, array $scope, string $registerId): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/registers/{$registerId}/sessions", [
            'openingCashAmount' => 100,
            'openingNote' => 'Receipt print shift opened.',
        ])
        ->assertCreated()
        ->json('data');
}

/**
 * @return array<string, mixed>
 */
function createPosReceiptSale(User $user, array $scope, string $registerId): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/sales', [
            'registerId' => $registerId,
            'saleChannel' => 'general_retail',
            'customerType' => 'anonymous',
            'customerName' => 'Walk-in receipt customer',
            'customerReference' => 'REC-REF-001',
            'notes' => 'Receipt print verification sale.',
            'lineItems' => [
                [
                    'itemType' => 'manual',
                    'itemCode' => 'REC-ITEM-001',
                    'itemName' => 'Receipt Item',
                    'quantity' => 2,
                    'unitPrice' => 25,
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 60,
                    'paymentReference' => 'REC-CASH-001',
                ],
            ],
        ])
        ->assertCreated()
        ->json('data');
}

it('renders the POS receipt print page with sale context when authorized', function (): void {
    $actor = makePosReceiptActor([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
    ]);
    $scope = seedPosReceiptScope($actor->id);
    $register = createPosReceiptRegister($actor, $scope);
    $session = openPosReceiptSession($actor, $scope, (string) $register['id']);
    $sale = createPosReceiptSale($actor, $scope, (string) $register['id']);

    $this->actingAs($actor)
        ->withHeaders($scope['headers'])
        ->get('/pos/sales/'.$sale['id'].'/print')
        ->assertInertia(fn (Assert $page) => $page
            ->component('pos/Print')
            ->where('sale.id', (string) $sale['id'])
            ->where('sale.saleNumber', $sale['saleNumber'])
            ->where('sale.receiptNumber', $sale['receiptNumber'])
            ->where('sale.register.registerName', 'Receipt Register')
            ->where('sale.session.sessionNumber', $session['sessionNumber'])
            ->where('sale.lineItems.0.itemName', 'Receipt Item')
            ->where('sale.payments.0.paymentMethod', 'cash')
            ->where('completedBy.name', $actor->name)
            ->where('generatedAt', fn (string $value): bool => $value !== ''));
});

it('downloads the POS receipt as a branded pdf when authorized', function (): void {
    $actor = makePosReceiptActor([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
    ]);
    $scope = seedPosReceiptScope($actor->id);
    $register = createPosReceiptRegister($actor, $scope);
    openPosReceiptSession($actor, $scope, (string) $register['id']);
    $sale = createPosReceiptSale($actor, $scope, (string) $register['id']);

    $response = $this->actingAs($actor)
        ->withHeaders($scope['headers'])
        ->withHeader('User-Agent', 'Afyanova-Test-Agent/1.0')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->get('/pos/sales/'.$sale['id'].'/pdf');

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Document-Format', 'pdf')
        ->assertHeader('X-Document-Schema-Version', 'document-pdf.v1')
        ->assertHeader('X-Document-Source', 'pos-sale')
        ->assertHeader('X-Document-Source-Id', (string) $sale['id']);

    expect((string) $response->headers->get('Content-Disposition'))
        ->toContain('.pdf');
    expect(substr((string) $response->getContent(), 0, 4))
        ->toBe('%PDF');
});
