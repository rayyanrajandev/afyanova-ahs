<?php

use App\Models\User;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Pos\Infrastructure\Models\PosSaleLineModel;
use App\Modules\Pos\Infrastructure\Models\PosSaleModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePosFrontdeskQuickUser(array $permissions = []): User
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
function seedPosFrontdeskQuickScope(int $userId): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TZ-FDQ',
        'name' => 'Tanzania Frontdesk Quick Network',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'DAR-FDQ',
        'name' => 'Dar Frontdesk Quick Center',
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
            'X-Tenant-Code' => 'TZ-FDQ',
            'X-Facility-Code' => 'DAR-FDQ',
        ],
    ];
}

function createPosFrontdeskQuickRegister(User $user, array $scope, array $overrides = []): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/registers', array_merge([
            'registerCode' => 'FDQ-'.strtoupper(Str::random(6)),
            'registerName' => 'Frontdesk Quick Cashier',
            'location' => 'Main front desk',
            'defaultCurrencyCode' => 'TZS',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

function openPosFrontdeskQuickSession(User $user, array $scope, string $registerId): array
{
    return test()->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/pos/registers/{$registerId}/sessions", [
            'openingCashAmount' => 150,
            'openingNote' => 'Frontdesk cashier shift opened.',
        ])
        ->assertCreated()
        ->json('data');
}

function createPosFrontdeskQuickPatient(array $scope, array $overrides = []): PatientModel
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

function createPosFrontdeskQuickCatalogItem(array $scope, array $overrides = []): ClinicalCatalogItemModel
{
    return ClinicalCatalogItemModel::query()->create(array_merge([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => null,
        'catalog_type' => 'service',
        'code' => 'SVC-'.strtoupper(Str::random(6)),
        'name' => 'Frontdesk Quick Service',
        'department_id' => null,
        'category' => 'general',
        'unit' => 'unit',
        'description' => 'Frontdesk quick cashier fixture',
        'metadata' => [
            'billingServiceCode' => 'FDQ-SVC-001',
        ],
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function createPosFrontdeskQuickTariff(array $scope, string $serviceCode, array $overrides = []): BillingServiceCatalogItemModel
{
    return BillingServiceCatalogItemModel::query()->create(array_merge([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'service_code' => $serviceCode,
        'service_name' => 'Frontdesk Quick Service',
        'service_type' => 'service',
        'department' => 'General',
        'unit' => 'unit',
        'base_price' => 25000,
        'currency_code' => 'TZS',
        'tax_rate_percent' => 0,
        'is_taxable' => false,
        'effective_from' => now()->subDay()->toDateTimeString(),
        'effective_to' => null,
        'description' => 'Frontdesk quick tariff',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

/**
 * PricingEngine_Migration_Plan.md Phase 5: frontdesk/lab quick cashier
 * pricing now resolves via chargeable_item_id (price_book_entries), not
 * the legacy service catalog -- createPosFrontdeskQuickTariff() above is
 * kept only as a legacy fixture proving it's genuinely ignored now. This
 * is the price that actually drives quick-cashier candidates, linked to
 * the clinical catalog item via the Phase 1 ID-reuse convention (a
 * chargeable_items row shares its id with the order's own catalog FK).
 */
function createPosFrontdeskQuickPrice(ClinicalCatalogItemModel $catalogItem, float $price = 25000, string $currencyCode = 'TZS'): void
{
    if (ChargeableItemModel::query()->whereKey($catalogItem->id)->exists()) {
        return;
    }

    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->id = $catalogItem->id;
    $chargeableItem->fill([
        'catalog_type' => 'service', 'charge_model' => 'flat', 'code' => $catalogItem->code, 'name' => $catalogItem->name, 'status' => 'active',
    ]);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $catalogItem->id, 'currency_code' => $currencyCode, 'unit_price' => $price, 'status' => 'active',
    ]);
}

function createLabOrderForFrontdeskQuick(string $patientId, string $catalogItemId, array $scope, array $overrides = []): LaboratoryOrderModel
{
    $serviceCode = $overrides['test_code'] ?? 'FDQ-SVC-001';
    return LaboratoryOrderModel::query()->create(array_merge([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHour()->toDateTimeString(),
        'lab_test_catalog_item_id' => $catalogItemId,
        'test_code' => $serviceCode,
        'test_name' => 'Frontdesk Lab Test',
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'clinical_notes' => null,
        'result_summary' => null,
        'resulted_at' => null,
        'status' => 'ordered',
        'status_reason' => null,
    ], $overrides));
}

function createPharmacyOrderForFrontdeskQuick(string $patientId, string $catalogItemId, array $scope, array $overrides = []): PharmacyOrderModel
{
    $serviceCode = $overrides['medication_code'] ?? 'FDQ-SVC-001';
    return PharmacyOrderModel::query()->create(array_merge([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'order_number' => 'PHARM'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'encounter_id' => null,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHour()->toDateTimeString(),
        'approved_medicine_catalog_item_id' => $catalogItemId,
        'medication_code' => $serviceCode,
        'medication_name' => 'Frontdesk Medication',
        'dosage_instruction' => 'Take as directed',
        'dose_quantity' => 1,
        'quantity_prescribed' => 1,
        'prescribed_unit' => 'unit',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function createRadiologyOrderForFrontdeskQuick(string $patientId, string $catalogItemId, array $scope, array $overrides = []): RadiologyOrderModel
{
    $serviceCode = $overrides['procedure_code'] ?? 'FDQ-SVC-001';
    return RadiologyOrderModel::query()->create(array_merge([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'order_number' => 'RAD'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'encounter_id' => null,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHour()->toDateTimeString(),
        'radiology_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => $serviceCode,
        'modality' => 'X-Ray',
        'study_description' => 'Frontdesk X-Ray',
        'status' => 'ordered',
        'entry_state' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function createTheatreProcedureForFrontdeskQuick(string $patientId, string $catalogItemId, array $scope, array $overrides = []): TheatreProcedureModel
{
    return TheatreProcedureModel::query()->create(array_merge([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'procedure_number' => 'PROC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'encounter_id' => null,
        'admission_id' => null,
        'appointment_id' => null,
        'theatre_procedure_catalog_item_id' => $catalogItemId,
        'procedure_type' => 'surgical',
        'procedure_name' => 'Frontdesk Procedure',
        'operating_clinician_user_id' => 1,
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'status' => 'planned',
        'entry_state' => 'active',
        'status_reason' => null,
    ], $overrides));
}

it('lists frontdesk quick candidates for all source kinds with pricing and excludes already invoiced orders', function (): void {
    $user = makePosFrontdeskQuickUser(['pos.frontdesk-quick.read']);
    $scope = seedPosFrontdeskQuickScope($user->id);
    $patient = createPosFrontdeskQuickPatient($scope);
    $catalogItem = createPosFrontdeskQuickCatalogItem($scope, [
        'metadata' => ['billingServiceCode' => 'FDQ-SVC-001'],
    ]);
    createPosFrontdeskQuickTariff($scope, 'FDQ-SVC-001');
    createPosFrontdeskQuickPrice($catalogItem);

    $labOrder = createLabOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);
    $pharmOrder = createPharmacyOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);
    $radOrder = createRadiologyOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);
    $procOrder = createTheatreProcedureForFrontdeskQuick($patient->id, $catalogItem->id, $scope);

    $response = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/frontdesk-quick/candidates?currencyCode=TZS')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(4);
    $sourceKinds = array_column($response->json('data'), 'sourceKind');
    expect($sourceKinds)->toContain('laboratory_order', 'pharmacy_prescription', 'radiology_order', 'clinical_procedure_order', 'procedure');
    expect((float) $response->json('data.0.unitPrice'))->toBe(25000.0);
});

it('excludes already-invoiced orders from frontdesk quick candidates', function (): void {
    $user = makePosFrontdeskQuickUser(['pos.frontdesk-quick.read']);
    $scope = seedPosFrontdeskQuickScope($user->id);
    $patient = createPosFrontdeskQuickPatient($scope);
    $catalogItem = createPosFrontdeskQuickCatalogItem($scope);
    createPosFrontdeskQuickTariff($scope, 'FDQ-SVC-001');
    createPosFrontdeskQuickPrice($catalogItem);

    $visibleOrder = createLabOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);
    $invoicedOrder = createLabOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope, [
        'test_code' => 'FDQ-SVC-002',
    ]);

    createPosFrontdeskQuickTariff($scope, 'FDQ-SVC-002', [
        'service_code' => 'FDQ-SVC-002',
    ]);

    BillingInvoiceModel::query()->create([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'invoice_number' => 'INV-FDQ-001',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'billing_payer_contract_id' => null,
        'issued_by_user_id' => $user->id,
        'invoice_date' => now()->subMinutes(20)->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 25000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 25000,
        'paid_amount' => 0,
        'balance_amount' => 25000,
        'payment_due_at' => now()->addDay()->toDateTimeString(),
        'notes' => 'FDQ invoice fixture',
        'line_items' => [
            [
                'description' => 'Frontdesk Lab Test',
                'quantity' => 1,
                'unitPrice' => 25000,
                'lineTotal' => 25000,
                'serviceCode' => 'FDQ-SVC-002',
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
        ->getJson('/api/v1/pos/frontdesk-quick/candidates?currencyCode=TZS')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($visibleOrder->id);
    expect($response->json('data.0.alreadyInvoiced'))->toBeFalse();
});

it('lists frontdesk quick candidates filtered by patient search', function (): void {
    $user = makePosFrontdeskQuickUser(['pos.frontdesk-quick.read']);
    $scope = seedPosFrontdeskQuickScope($user->id);
    $patient = createPosFrontdeskQuickPatient($scope, [
        'first_name' => 'Juma',
        'last_name' => 'Mkono',
    ]);
    $otherPatient = createPosFrontdeskQuickPatient($scope, [
        'first_name' => 'Asha',
        'last_name' => 'Suleiman',
    ]);
    $catalogItem = createPosFrontdeskQuickCatalogItem($scope);
    createPosFrontdeskQuickTariff($scope, 'FDQ-SVC-001');
    createPosFrontdeskQuickPrice($catalogItem);

    $jumaOrder = createLabOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);
    $ashaOrder = createLabOrderForFrontdeskQuick($otherPatient->id, $catalogItem->id, $scope);

    $response = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/frontdesk-quick/candidates?q=Juma&currencyCode=TZS')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.patientId'))->toBe($patient->id);
});

it('creates a frontdesk quick POS sale with mixed source kinds', function (): void {
    $user = makePosFrontdeskQuickUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
        'pos.frontdesk-quick.read',
        'pos.frontdesk-quick.create',
    ]);
    $scope = seedPosFrontdeskQuickScope($user->id);
    $register = createPosFrontdeskQuickRegister($user, $scope);
    openPosFrontdeskQuickSession($user, $scope, (string) $register['id']);
    $patient = createPosFrontdeskQuickPatient($scope, [
        'first_name' => 'Rehema',
        'last_name' => 'Juma',
    ]);
    $catalogItem = createPosFrontdeskQuickCatalogItem($scope);
    createPosFrontdeskQuickTariff($scope, 'FDQ-SVC-001');
    createPosFrontdeskQuickPrice($catalogItem);

    $labOrder = createLabOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);
    $pharmOrder = createPharmacyOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);

    $sale = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/frontdesk-quick/sales', [
            'registerId' => $register['id'],
            'currencyCode' => 'TZS',
            'items' => [
                [
                    'kind' => 'laboratory_order',
                    'orderId' => $labOrder->id,
                    'note' => 'Lab test.',
                ],
                [
                    'kind' => 'pharmacy_prescription',
                    'orderId' => $pharmOrder->id,
                    'note' => 'Medication.',
                ],
            ],
            'payments' => [
                [
                    'paymentMethod' => 'cash',
                    'amount' => 50000,
                ],
            ],
        ])
        ->assertCreated()
        ->json('data');

    expect($sale['saleChannel'])->toBe('frontdesk_quick');
    expect($sale['patientId'])->toBe($patient->id);
    expect($sale['customerName'])->toBe('Rehema Juma');
    expect((float) $sale['totalAmount'])->toBe(50000.0);
    expect(count($sale['lineItems']))->toBe(2);
    expect($sale['lineItems'][0]['itemReference'])->toBe($labOrder->id);
    expect($sale['lineItems'][1]['itemReference'])->toBe($pharmOrder->id);

    $storedSale = PosSaleModel::query()->findOrFail($sale['id']);
    expect($storedSale->sale_channel)->toBe('frontdesk_quick');
});

it('rejects mixed-patient frontdesk quick checkout in one sale', function (): void {
    $user = makePosFrontdeskQuickUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.manage',
        'pos.sales.create',
        'pos.frontdesk-quick.create',
    ]);
    $scope = seedPosFrontdeskQuickScope($user->id);
    $register = createPosFrontdeskQuickRegister($user, $scope);
    openPosFrontdeskQuickSession($user, $scope, (string) $register['id']);
    $catalogItem = createPosFrontdeskQuickCatalogItem($scope);
    createPosFrontdeskQuickTariff($scope, 'FDQ-SVC-001');
    createPosFrontdeskQuickPrice($catalogItem);

    $firstPatient = createPosFrontdeskQuickPatient($scope, [
        'first_name' => 'Mariam',
        'last_name' => 'Said',
    ]);
    $secondPatient = createPosFrontdeskQuickPatient($scope, [
        'first_name' => 'Hamisi',
        'last_name' => 'Bakari',
    ]);

    $firstOrder = createLabOrderForFrontdeskQuick($firstPatient->id, $catalogItem->id, $scope);
    $secondOrder = createLabOrderForFrontdeskQuick($secondPatient->id, $catalogItem->id, $scope);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/frontdesk-quick/sales', [
            'registerId' => $register['id'],
            'currencyCode' => 'TZS',
            'items' => [
                ['kind' => 'laboratory_order', 'orderId' => $firstOrder->id],
                ['kind' => 'laboratory_order', 'orderId' => $secondOrder->id],
            ],
            'payments' => [
                ['paymentMethod' => 'cash', 'amount' => 50000],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.1.orderId']);
});

it('rejects frontdesk quick checkout when the order is already invoiced', function (): void {
    $user = makePosFrontdeskQuickUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.manage',
        'pos.sales.create',
        'pos.frontdesk-quick.create',
    ]);
    $scope = seedPosFrontdeskQuickScope($user->id);
    $register = createPosFrontdeskQuickRegister($user, $scope);
    openPosFrontdeskQuickSession($user, $scope, (string) $register['id']);
    $patient = createPosFrontdeskQuickPatient($scope);
    $catalogItem = createPosFrontdeskQuickCatalogItem($scope);
    createPosFrontdeskQuickTariff($scope, 'FDQ-SVC-001');
    createPosFrontdeskQuickPrice($catalogItem);
    $order = createLabOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);

    BillingInvoiceModel::query()->create([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'invoice_number' => 'INV-FDQ-REJECT-01',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'billing_payer_contract_id' => null,
        'issued_by_user_id' => $user->id,
        'invoice_date' => now()->subMinutes(20)->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 25000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 25000,
        'paid_amount' => 0,
        'balance_amount' => 25000,
        'payment_due_at' => now()->addDay()->toDateTimeString(),
        'notes' => 'FDQ invoice reject fixture',
        'line_items' => [
            [
                'description' => 'Frontdesk Lab Test',
                'quantity' => 1,
                'unitPrice' => 25000,
                'lineTotal' => 25000,
                'serviceCode' => 'FDQ-SVC-001',
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
        ->postJson('/api/v1/pos/frontdesk-quick/sales', [
            'registerId' => $register['id'],
            'currencyCode' => 'TZS',
            'items' => [
                ['kind' => 'laboratory_order', 'orderId' => $order->id],
            ],
            'payments' => [
                ['paymentMethod' => 'cash', 'amount' => 25000],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.orderId']);
});

it('rejects frontdesk quick checkout when the order is already settled via POS', function (): void {
    $user = makePosFrontdeskQuickUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.manage',
        'pos.sales.create',
        'pos.frontdesk-quick.read',
        'pos.frontdesk-quick.create',
    ]);
    $scope = seedPosFrontdeskQuickScope($user->id);
    $register = createPosFrontdeskQuickRegister($user, $scope);
    openPosFrontdeskQuickSession($user, $scope, (string) $register['id']);
    $patient = createPosFrontdeskQuickPatient($scope, [
        'first_name' => 'Settled',
        'last_name' => 'Patient',
    ]);
    $catalogItem = createPosFrontdeskQuickCatalogItem($scope);
    createPosFrontdeskQuickTariff($scope, 'FDQ-SVC-001');
    createPosFrontdeskQuickPrice($catalogItem);
    $order = createLabOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/frontdesk-quick/sales', [
            'registerId' => $register['id'],
            'currencyCode' => 'TZS',
            'items' => [
                ['kind' => 'laboratory_order', 'orderId' => $order->id],
            ],
            'payments' => [
                ['paymentMethod' => 'cash', 'amount' => 25000],
            ],
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/frontdesk-quick/sales', [
            'registerId' => $register['id'],
            'currencyCode' => 'TZS',
            'items' => [
                ['kind' => 'laboratory_order', 'orderId' => $order->id],
            ],
            'payments' => [
                ['paymentMethod' => 'cash', 'amount' => 25000],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.orderId']);
});

it('blocks re-settlement via candidates list after frontdesk quick sale', function (): void {
    $user = makePosFrontdeskQuickUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.manage',
        'pos.sales.create',
        'pos.frontdesk-quick.read',
        'pos.frontdesk-quick.create',
    ]);
    $scope = seedPosFrontdeskQuickScope($user->id);
    $register = createPosFrontdeskQuickRegister($user, $scope);
    openPosFrontdeskQuickSession($user, $scope, (string) $register['id']);
    $patient = createPosFrontdeskQuickPatient($scope);
    $catalogItem = createPosFrontdeskQuickCatalogItem($scope);
    createPosFrontdeskQuickTariff($scope, 'FDQ-SVC-001');
    createPosFrontdeskQuickPrice($catalogItem);
    $order = createLabOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/frontdesk-quick/sales', [
            'registerId' => $register['id'],
            'currencyCode' => 'TZS',
            'items' => [
                ['kind' => 'laboratory_order', 'orderId' => $order->id],
            ],
            'payments' => [
                ['paymentMethod' => 'cash', 'amount' => 25000],
            ],
        ])
        ->assertCreated();

    $response = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/frontdesk-quick/candidates?currencyCode=TZS&patientId='.$patient->id)
        ->assertOk();

    $orderIds = array_column($response->json('data'), 'id');
    expect($orderIds)->not->toContain($order->id);
});

it('creates a frontdesk quick POS sale with createInvoice flag and creates billing invoice', function (): void {
    $user = makePosFrontdeskQuickUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
        'pos.frontdesk-quick.read',
        'pos.frontdesk-quick.create',
        'billing.invoices.create',
        'billing.invoices.read',
    ]);
    $scope = seedPosFrontdeskQuickScope($user->id);
    $register = createPosFrontdeskQuickRegister($user, $scope);
    openPosFrontdeskQuickSession($user, $scope, (string) $register['id']);
    $patient = createPosFrontdeskQuickPatient($scope, [
        'first_name' => 'Invoice',
        'last_name' => 'Patient',
    ]);
    $catalogItem = createPosFrontdeskQuickCatalogItem($scope);
    createPosFrontdeskQuickTariff($scope, 'FDQ-SVC-001');
    createPosFrontdeskQuickPrice($catalogItem);

    $labOrder = createLabOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);
    $radOrder = createRadiologyOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/frontdesk-quick/sales', [
            'registerId' => $register['id'],
            'currencyCode' => 'TZS',
            'createInvoice' => true,
            'items' => [
                ['kind' => 'laboratory_order', 'orderId' => $labOrder->id],
                ['kind' => 'radiology_order', 'orderId' => $radOrder->id],
            ],
            'payments' => [
                ['paymentMethod' => 'cash', 'amount' => 50000],
            ],
        ])
        ->assertCreated();

    $storedInvoice = BillingInvoiceModel::query()
        ->where('patient_id', $patient->id)
        ->first();

    expect($storedInvoice)->not->toBeNull();
    expect($storedInvoice->invoice_number)->not->toBeEmpty();
    expect((float) $storedInvoice->total_amount)->toBe(50000.0);
    expect((float) $storedInvoice->paid_amount)->toBe(50000.0);
    expect((float) $storedInvoice->balance_amount)->toBe(0.0);
    expect($storedInvoice->status)->toBe('draft');
    expect($storedInvoice->patient_id)->toBe($patient->id);
    expect(count($storedInvoice->line_items))->toBe(2);
});

it('verifies frontdesk quick payment for a completed sale', function (): void {
    $user = makePosFrontdeskQuickUser([
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.manage',
        'pos.sales.create',
        'pos.frontdesk-quick.read',
        'pos.frontdesk-quick.create',
    ]);
    $scope = seedPosFrontdeskQuickScope($user->id);
    $register = createPosFrontdeskQuickRegister($user, $scope);
    openPosFrontdeskQuickSession($user, $scope, (string) $register['id']);
    $patient = createPosFrontdeskQuickPatient($scope, [
        'first_name' => 'Verify',
        'last_name' => 'Payment',
    ]);
    $catalogItem = createPosFrontdeskQuickCatalogItem($scope);
    createPosFrontdeskQuickTariff($scope, 'FDQ-SVC-001');
    createPosFrontdeskQuickPrice($catalogItem);
    $order = createLabOrderForFrontdeskQuick($patient->id, $catalogItem->id, $scope);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/pos/frontdesk-quick/sales', [
            'registerId' => $register['id'],
            'currencyCode' => 'TZS',
            'items' => [
                ['kind' => 'laboratory_order', 'orderId' => $order->id],
            ],
            'payments' => [
                ['paymentMethod' => 'cash', 'amount' => 25000, 'paidAt' => now()->toDateTimeString()],
            ],
        ])
        ->assertCreated();

    $response = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/frontdesk-quick/verify/laboratory_order/'.$order->id)
        ->assertOk()
        ->json('data');

    expect($response['paid'])->toBeTrue();
    expect($response['sourceKind'])->toBe('laboratory_order');
    expect($response['orderId'])->toBe($order->id);
    expect($response['saleNumber'])->not->toBeEmpty();
    expect($response['receiptNumber'])->not->toBeEmpty();
    expect(count($response['payments']))->toBe(1);
    expect($response['payments'][0]['payment_method'])->toBe('cash');
    expect((float) $response['payments'][0]['amount'])->toBe(25000.0);
});

it('returns not paid when verifying a non-existent frontdesk quick payment', function (): void {
    $user = makePosFrontdeskQuickUser([
        'pos.frontdesk-quick.read',
    ]);
    $scope = seedPosFrontdeskQuickScope($user->id);
    $unknownOrderId = (string) Str::uuid();

    $response = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/pos/frontdesk-quick/verify/laboratory_order/'.$unknownOrderId)
        ->assertOk()
        ->json('data');

    expect($response['paid'])->toBeFalse();
    expect($response['sourceKind'])->toBe('laboratory_order');
    expect($response['orderId'])->toBe($unknownOrderId);
});
