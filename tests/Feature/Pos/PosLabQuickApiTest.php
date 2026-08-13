<?php

use App\Models\User;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Pos\Infrastructure\Models\PosSaleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePosLabQuickUser(array $permissions = []): User
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
function seedPosLabQuickScope(int $userId): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TZ-PLQ',
        'name' => 'Tanzania Lab Quick Network',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'DAR-PLQ',
        'name' => 'Dar Lab Quick Center',
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
            'X-Tenant-Code' => 'TZ-PLQ',
            'X-Facility-Code' => 'DAR-PLQ',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function createPosLabQuickRegister(User $user, array $scope, array $overrides = []): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/registers', array_merge([
            'registerCode' => 'LAB-'.strtoupper(Str::random(6)),
            'registerName' => 'Lab Quick Cashier',
            'location' => 'Laboratory front desk',
            'defaultCurrencyCode' => 'TZS',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

/**
 * @return array<string, mixed>
 */
function openPosLabQuickSession(User $user, array $scope, string $registerId): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/registers/{$registerId}/sessions", [
            'openingCashAmount' => 150,
            'openingNote' => 'Lab cashier shift opened.',
        ])
        ->assertCreated()
        ->json('data');
}

function createPosLabQuickPatient(array $scope, array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'tenant_id' => $scope['tenantId'],
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Amina',
        'middle_name' => null,
        'last_name' => 'Moshi',
        'gender' => 'female',
        'date_of_birth' => '1996-04-21',
        'phone' => '+255700000001',
        'email' => null,
        'national_id' => null,
        'country_code' => 'TZ',
        'region' => null,
        'district' => null,
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function createPosLabQuickCatalogItem(array $scope, array $overrides = []): ClinicalCatalogItemModel
{
    return ClinicalCatalogItemModel::query()->create(array_merge([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => null,
        'catalog_type' => 'lab_test',
        'code' => 'LAB-CBC-001',
        'name' => 'Complete Blood Count',
        'department_id' => null,
        'category' => 'hematology',
        'unit' => 'test',
        'description' => 'Lab quick cashier fixture',
        'metadata' => [
            'billingServiceCode' => 'LAB-CBC-001',
        ],
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function createPosLabQuickTariff(array $scope, array $overrides = []): BillingServiceCatalogItemModel
{
    return BillingServiceCatalogItemModel::query()->create(array_merge([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'service_code' => 'LAB-CBC-001',
        'service_name' => 'Complete Blood Count',
        'service_type' => 'laboratory',
        'department' => 'Laboratory',
        'unit' => 'test',
        'base_price' => 35000,
        'currency_code' => 'TZS',
        'tax_rate_percent' => 0,
        'is_taxable' => false,
        'effective_from' => now()->subDay()->toDateTimeString(),
        'effective_to' => null,
        'description' => 'Lab quick cashier tariff',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

/**
 * PricingEngine_Migration_Plan.md Phase 5: lab quick cashier pricing now
 * resolves via chargeable_item_id (price_book_entries), not the legacy
 * service catalog -- createPosLabQuickTariff() above is kept only as a
 * legacy fixture proving it's genuinely ignored now. This is the price
 * that actually drives quick-cashier candidates, linked to the clinical
 * catalog item via the Phase 1 ID-reuse convention.
 */
function createPosLabQuickPrice(ClinicalCatalogItemModel $catalogItem, float $price = 35000, string $currencyCode = 'TZS'): void
{
    if (ChargeableItemModel::query()->whereKey($catalogItem->id)->exists()) {
        return;
    }

    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->id = $catalogItem->id;
    $chargeableItem->fill([
        'catalog_type' => 'lab_test', 'charge_model' => 'flat', 'code' => $catalogItem->code, 'name' => $catalogItem->name, 'status' => 'active',
    ]);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $catalogItem->id, 'currency_code' => $currencyCode, 'unit_price' => $price, 'status' => 'active',
    ]);
}

function createPosLabQuickOrder(string $patientId, array $scope, array $overrides = []): LaboratoryOrderModel
{
    return LaboratoryOrderModel::query()->create(array_merge([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHour()->toDateTimeString(),
        'lab_test_catalog_item_id' => null,
        'test_code' => 'LAB-CBC-001',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'clinical_notes' => null,
        'result_summary' => null,
        'resulted_at' => null,
        'status' => 'ordered',
        'status_reason' => null,
    ], $overrides));
}

it('lists payable laboratory quick cashier candidates with governed pricing and excludes already invoiced orders', function (): void {
    $user = makePosLabQuickUser([
        'pos.lab-quick.read',
    ]);
    $scope = seedPosLabQuickScope($user->id);
    $patient = createPosLabQuickPatient($scope);
    $catalogItem = createPosLabQuickCatalogItem($scope);
    createPosLabQuickTariff($scope);
    createPosLabQuickPrice($catalogItem);

    $visibleOrder = createPosLabQuickOrder($patient->id, $scope, [
        'lab_test_catalog_item_id' => $catalogItem->id,
        'test_code' => 'LAB-CBC-001',
        'test_name' => 'Complete Blood Count',
        'status' => 'ordered',
    ]);

    $invoicedOrder = createPosLabQuickOrder($patient->id, $scope, [
        'lab_test_catalog_item_id' => $catalogItem->id,
        'test_code' => 'LAB-CBC-001',
        'test_name' => 'Complete Blood Count Repeat',
        'status' => 'completed',
        'resulted_at' => now()->subMinutes(40)->toDateTimeString(),
    ]);

    BillingInvoiceModel::query()->create([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'invoice_number' => 'INV-LAB-001',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'billing_payer_contract_id' => null,
        'issued_by_user_id' => $user->id,
        'invoice_date' => now()->subMinutes(20)->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 35000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 35000,
        'paid_amount' => 0,
        'balance_amount' => 35000,
        'payment_due_at' => now()->addDay()->toDateTimeString(),
        'notes' => 'Lab invoice fixture',
        'line_items' => [
            [
                'description' => 'Complete Blood Count Repeat',
                'quantity' => 1,
                'unitPrice' => 35000,
                'lineTotal' => 35000,
                'serviceCode' => 'LAB-CBC-001',
                'sourceWorkflowKind' => 'laboratory_order',
                'sourceWorkflowId' => $invoicedOrder->id,
            ],
        ],
        'pricing_mode' => 'catalog',
        'pricing_context' => null,
        'status' => 'issued',
        'status_reason' => null,
    ]);

    $response = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/lab-quick/candidates?currencyCode=TZS')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($visibleOrder->id);
    expect($response->json('data.0.patientId'))->toBe($patient->id);
    expect($response->json('data.0.patientNumber'))->toBe($patient->patient_number);
    expect($response->json('data.0.serviceCode'))->toBe('LAB-CBC-001');
    expect((float) $response->json('data.0.unitPrice'))->toBe(35000.0);
});

it('creates a lab quick cashier sale, links it to the patient order, and then blocks re-settlement', function (): void {
    $user = makePosLabQuickUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
        'pos.lab-quick.read',
        'pos.lab-quick.create',
    ]);
    $scope = seedPosLabQuickScope($user->id);
    $register = createPosLabQuickRegister($user, $scope);
    openPosLabQuickSession($user, $scope, (string) $register['id']);
    $patient = createPosLabQuickPatient($scope, [
        'first_name' => 'Rehema',
        'last_name' => 'Juma',
    ]);
    $catalogItem = createPosLabQuickCatalogItem($scope);
    createPosLabQuickTariff($scope);
    createPosLabQuickPrice($catalogItem);
    $order = createPosLabQuickOrder($patient->id, $scope, [
        'lab_test_catalog_item_id' => $catalogItem->id,
    ]);

    $sale = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/lab-quick/sales', [
            'registerId' => $register['id'],
            'currencyCode' => 'TZS',
            'items' => [
                [
                    'orderId' => $order->id,
                    'note' => 'Paid before specimen processing.',
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 40000,
                ],
            ],
        ])
        ->assertCreated()
        ->json('data');

    expect($sale['saleChannel'])->toBe('lab_quick');
    expect($sale['patientId'])->toBe($patient->id);
    expect($sale['customerType'])->toBe('patient');
    expect($sale['customerName'])->toBe('Rehema Juma');
    expect((float) $sale['totalAmount'])->toBe(35000.0);
    expect((float) $sale['changeAmount'])->toBe(5000.0);
    expect($sale['lineItems'][0]['itemType'])->toBe('service');
    expect($sale['lineItems'][0]['itemReference'])->toBe($order->id);
    expect($sale['lineItems'][0]['itemCode'])->toBe('LAB-CBC-001');
    expect($sale['lineItems'][0]['metadata']['sourceWorkflowKind'] ?? null)->toBe('laboratory_order');
    expect($sale['lineItems'][0]['metadata']['billingServiceCode'] ?? null)->toBe('LAB-CBC-001');

    $storedSale = PosSaleModel::query()->findOrFail($sale['id']);
    expect($storedSale->sale_channel)->toBe('lab_quick');

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/lab-quick/candidates?currencyCode=TZS')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/lab-quick/sales', [
            'registerId' => $register['id'],
            'currencyCode' => 'TZS',
            'items' => [
                [
                    'orderId' => $order->id,
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 35000,
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.orderId']);
});

it('rejects mixed-patient laboratory quick cashier checkout in one sale', function (): void {
    $user = makePosLabQuickUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.manage',
        'pos.sales.create',
        'pos.lab-quick.create',
    ]);
    $scope = seedPosLabQuickScope($user->id);
    $register = createPosLabQuickRegister($user, $scope);
    openPosLabQuickSession($user, $scope, (string) $register['id']);
    $catalogItem = createPosLabQuickCatalogItem($scope);
    createPosLabQuickTariff($scope);
    createPosLabQuickPrice($catalogItem);

    $firstPatient = createPosLabQuickPatient($scope, [
        'first_name' => 'Mariam',
        'last_name' => 'Said',
    ]);
    $secondPatient = createPosLabQuickPatient($scope, [
        'first_name' => 'Hamisi',
        'last_name' => 'Bakari',
    ]);

    $firstOrder = createPosLabQuickOrder($firstPatient->id, $scope, [
        'lab_test_catalog_item_id' => $catalogItem->id,
    ]);
    $secondOrder = createPosLabQuickOrder($secondPatient->id, $scope, [
        'lab_test_catalog_item_id' => $catalogItem->id,
    ]);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/lab-quick/sales', [
            'registerId' => $register['id'],
            'currencyCode' => 'TZS',
            'items' => [
                ['orderId' => $firstOrder->id],
                ['orderId' => $secondOrder->id],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 70000,
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.1.orderId']);
});

it('rejects laboratory quick cashier checkout when the order is already invoiced', function (): void {
    $user = makePosLabQuickUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.manage',
        'pos.sales.create',
        'pos.lab-quick.create',
    ]);
    $scope = seedPosLabQuickScope($user->id);
    $register = createPosLabQuickRegister($user, $scope);
    openPosLabQuickSession($user, $scope, (string) $register['id']);
    $patient = createPosLabQuickPatient($scope);
    $catalogItem = createPosLabQuickCatalogItem($scope);
    createPosLabQuickTariff($scope);
    createPosLabQuickPrice($catalogItem);
    $order = createPosLabQuickOrder($patient->id, $scope, [
        'lab_test_catalog_item_id' => $catalogItem->id,
    ]);

    BillingInvoiceModel::query()->create([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'invoice_number' => 'INV-LAB-REJECT-01',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'billing_payer_contract_id' => null,
        'issued_by_user_id' => $user->id,
        'invoice_date' => now()->subMinutes(20)->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 35000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 35000,
        'paid_amount' => 0,
        'balance_amount' => 35000,
        'payment_due_at' => now()->addDay()->toDateTimeString(),
        'notes' => 'Lab invoice reject fixture',
        'line_items' => [
            [
                'description' => 'Complete Blood Count',
                'quantity' => 1,
                'unitPrice' => 35000,
                'lineTotal' => 35000,
                'serviceCode' => 'LAB-CBC-001',
                'sourceWorkflowKind' => 'laboratory_order',
                'sourceWorkflowId' => $order->id,
            ],
        ],
        'pricing_mode' => 'catalog',
        'pricing_context' => null,
        'status' => 'issued',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/lab-quick/sales', [
            'registerId' => $register['id'],
            'currencyCode' => 'TZS',
            'items' => [
                [
                    'orderId' => $order->id,
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 35000,
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.orderId']);
});
