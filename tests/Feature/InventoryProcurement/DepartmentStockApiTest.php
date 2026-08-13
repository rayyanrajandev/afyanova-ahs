<?php

use App\Models\User;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\DepartmentStockBalanceModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\DepartmentStockMovementModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function deptStockSetup(array $userPermissions = []): array
{
    $tenant = TenantModel::create([
        'code' => 'DEPT-STK-'.Str::upper(Str::random(4)),
        'name' => 'Department Stock Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $facility = FacilityModel::create([
        'tenant_id' => $tenant->id,
        'code' => 'DEPT-STK-FAC',
        'name' => 'Department Stock Facility',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    $department = DepartmentModel::create([
        'tenant_id' => $tenant->id,
        'facility_id' => $facility->id,
        'code' => 'DEPT-OPD',
        'name' => 'Outpatient Department',
        'service_type' => 'clinical',
        'status' => 'active',
    ]);

    $warehouse = InventoryWarehouseModel::create([
        'tenant_id' => $tenant->id,
        'facility_id' => $facility->id,
        'warehouse_code' => 'WH-MAIN',
        'warehouse_name' => 'Main Warehouse',
        'warehouse_type' => 'central',
        'status' => 'active',
    ]);

    $role = RoleModel::create([
        'tenant_id' => $tenant->id,
        'facility_id' => $facility->id,
        'code' => 'DEPT-STK-ROLE',
        'name' => 'Department Stock Role',
        'status' => 'active',
    ]);

    $user = User::create([
        'name' => 'Dept Stock User',
        'email' => 'dept-stock-'.Str::random(6).'@test.com',
        'password' => bcrypt('password'),
    ]);

    $user->roles()->attach($role->id);
    foreach ($userPermissions as $perm) {
        $user->givePermissionTo($perm);
    }

    DB::table('facility_user')->insert([
        'facility_id' => $facility->id,
        'user_id' => $user->id,
        'role' => 'inventory_manager',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return compact('user', 'tenant', 'facility', 'department', 'warehouse');
}

function deptStockHeaders(array $scope): array
{
    return [
        'X-Tenant-Code' => $scope['tenant']->code,
        'X-Facility-Code' => $scope['facility']->code,
    ];
}

function deptStockItem(array $scope, array $overrides = []): array
{
    $item = InventoryItemModel::create(array_merge([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'item_code' => 'ITM-'.Str::upper(Str::random(6)),
        'item_name' => 'Test Consumable',
        'category' => 'medical_consumable',
        'unit' => 'box',
        'current_stock' => 50,
        'reorder_level' => 5,
        'default_warehouse_id' => $scope['warehouse']->id,
        'status' => 'active',
    ], $overrides))->toArray();

    InventoryItemUnitModel::create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'item_id' => $item['id'],
        'unit_name' => $item['unit'],
        'unit_code' => $item['unit'],
        'base_quantity' => 1.0,
        'is_base_unit' => true,
        'is_default_sales_unit' => true,
        'is_default_purchase_unit' => true,
        'is_active' => true,
    ]);

    return $item;
}

function deptStockCreateBalance(array $scope, string $itemId, float $onHand = 20): DepartmentStockBalanceModel
{
    return DepartmentStockBalanceModel::create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'department_id' => $scope['department']->id,
        'item_id' => $itemId,
        'batch_id' => null,
        'quantity_on_hand' => $onHand,
        'quantity_consumed' => 0,
        'quantity_returned' => 0,
        'quantity_wasted' => 0,
        'unit' => 'box',
        'last_issued_at' => now(),
        'last_consumed_at' => null,
    ]);
}

// ─── Balance API Tests ─────────────────────────────────────

it('returns empty balances for a department with no stock', function (): void {
    $scope = deptStockSetup(['inventory.procurement.read', 'inventory.procurement.manage-warehouses']);
    $headers = deptStockHeaders($scope);

    $this->actingAs($scope['user'])
        ->getJson("/api/v1/inventory-procurement/department-stock-balances?departmentId={$scope['department']->id}", $headers)
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonCount(0, 'data');
});

it('lists department stock balances after creation', function (): void {
    $scope = deptStockSetup(['inventory.procurement.read', 'inventory.procurement.manage-warehouses']);
    $headers = deptStockHeaders($scope);
    $item = deptStockItem($scope);

    deptStockCreateBalance($scope, $item['id'], 20);

    $response = $this->actingAs($scope['user'])
        ->getJson("/api/v1/inventory-procurement/department-stock-balances?departmentId={$scope['department']->id}", $headers)
        ->assertOk();

    $data = $response->json('data');
    expect(count($data))->toBe(1);
    expect((float) $data[0]['quantityOnHand'])->toEqualWithDelta(20, 0.001);
    expect((float) $data[0]['quantityConsumed'])->toEqualWithDelta(0, 0.001);
});

// ─── Consumption via Pharmacy Dispense ──────────────────────

it('records department stock consumption when pharmacy order is dispensed with linked appointment', function (): void {
    $scope = deptStockSetup([
        'inventory.procurement.read',
        'inventory.procurement.manage-items',
        'pharmacy.orders.update-status',
        'pharmacy.orders.create',
    ]);
    $headers = deptStockHeaders($scope);

    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'catalog_type' => 'formulary_item',
        'code' => 'MED-PARA-500',
        'name' => 'Paracetamol 500mg',
        'category' => 'analgesics',
        'unit' => 'tablet',
        'status' => 'active',
    ]);

    $item = deptStockItem($scope, [
        'item_code' => 'PHARM-TEST',
        'item_name' => 'Paracetamol 500mg',
        'category' => 'pharmaceutical',
        'clinical_catalog_item_id' => $catalogItem->id,
    ]);

    $patientId = (string) Str::uuid();
    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT'.Str::upper(Str::random(6)),
        'first_name' => 'Test',
        'last_name' => 'Patient',
        'gender' => 'male',
        'date_of_birth' => '1990-01-01',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $appointmentId = (string) Str::uuid();
    DB::table('appointments')->insert([
        'id' => $appointmentId,
        'appointment_number' => 'APT'.Str::upper(Str::random(6)),
        'patient_id' => $patientId,
        'department' => $scope['department']->name,
        'scheduled_at' => now()->subHour(),
        'duration_minutes' => 30,
        'status' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    deptStockCreateBalance($scope, $item['id'], 50);

    $orderId = (string) Str::uuid();
    DB::table('pharmacy_orders')->insert([
        'id' => $orderId,
        'order_number' => 'RX'.Str::upper(Str::random(6)),
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'medication_code' => 'PHARM-TEST',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => '500mg twice daily',
        'quantity_prescribed' => 10,
        'quantity_dispensed' => 0,
        'prescribed_unit' => 'box',
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'ordered_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($scope['user'])
        ->patchJson("/api/v1/pharmacy-orders/{$orderId}/status", array_merge(
            $headers,
            ['status' => 'in_preparation'],
        ))
        ->assertOk();

    $this->actingAs($scope['user'])
        ->patchJson("/api/v1/pharmacy-orders/{$orderId}/status", array_merge(
            $headers,
            ['status' => 'dispensed', 'quantity_dispensed' => 10],
        ))
        ->assertOk();

    $balance = DepartmentStockBalanceModel::query()
        ->where('department_id', $scope['department']->id)
        ->where('item_id', $item['id'])
        ->first();

    expect($balance)->not->toBeNull();
    expect((float) $balance->quantity_on_hand)->toEqualWithDelta(40, 0.001);
    expect((float) $balance->quantity_consumed)->toEqualWithDelta(10, 0.001);

    $movement = DepartmentStockMovementModel::query()
        ->where('department_id', $scope['department']->id)
        ->where('item_id', $item['id'])
        ->where('movement_type', 'consume')
        ->first();

    expect($movement)->not->toBeNull();
    expect((float) $movement->quantity)->toEqualWithDelta(10, 0.001);
    expect($movement->source)->toBe('pharmacy_dispense');
});

// ─── Return & Wastage Endpoint Tests ────────────────────────

it('records department stock return via API', function (): void {
    $scope = deptStockSetup(['inventory.procurement.manage-warehouses', 'inventory.procurement.create-request']);
    $headers = deptStockHeaders($scope);
    $item = deptStockItem($scope);

    deptStockCreateBalance($scope, $item['id'], 30);

    $response = $this->actingAs($scope['user'])
        ->postJson("/api/v1/inventory-procurement/department-stock-balances/{$scope['department']->id}/return", array_merge(
            $headers,
            [
                'item_id' => $item['id'],
                'quantity' => 5,
                'notes' => 'Returning unused stock',
            ],
        ))
        ->assertOk();

    $data = $response->json('data');
    expect((float) $data['quantity_on_hand'])->toEqualWithDelta(25, 0.001);
    expect((float) $data['quantity_returned'])->toEqualWithDelta(5, 0.001);

    $movement = DepartmentStockMovementModel::query()
        ->where('department_id', $scope['department']->id)
        ->where('movement_type', 'return')
        ->first();

    expect($movement)->not->toBeNull();
    expect((float) $movement->quantity)->toEqualWithDelta(5, 0.001);
});

it('records department stock wastage via API', function (): void {
    $scope = deptStockSetup(['inventory.procurement.manage-warehouses', 'inventory.procurement.create-request']);
    $headers = deptStockHeaders($scope);
    $item = deptStockItem($scope);

    deptStockCreateBalance($scope, $item['id'], 30);

    $response = $this->actingAs($scope['user'])
        ->postJson("/api/v1/inventory-procurement/department-stock-balances/{$scope['department']->id}/wastage", array_merge(
            $headers,
            [
                'item_id' => $item['id'],
                'quantity' => 3,
                'notes' => 'Damaged packaging',
            ],
        ))
        ->assertOk();

    $data = $response->json('data');
    expect((float) $data['quantity_on_hand'])->toEqualWithDelta(27, 0.001);
    expect((float) $data['quantity_wasted'])->toEqualWithDelta(3, 0.001);

    $movement = DepartmentStockMovementModel::query()
        ->where('department_id', $scope['department']->id)
        ->where('movement_type', 'waste')
        ->first();

    expect($movement)->not->toBeNull();
    expect((float) $movement->quantity)->toEqualWithDelta(3, 0.001);
});

it('returns 404 when returning stock that does not exist', function (): void {
    $scope = deptStockSetup(['inventory.procurement.manage-warehouses', 'inventory.procurement.create-request']);
    $headers = deptStockHeaders($scope);
    $item = deptStockItem($scope);

    $this->actingAs($scope['user'])
        ->postJson("/api/v1/inventory-procurement/department-stock-balances/{$scope['department']->id}/return", array_merge(
            $headers,
            [
                'item_id' => $item['id'],
                'quantity' => 5,
            ],
        ))
        ->assertNotFound();
});

it('validates required fields on return and wastage endpoints', function (): void {
    $scope = deptStockSetup(['inventory.procurement.manage-warehouses', 'inventory.procurement.create-request']);
    $headers = deptStockHeaders($scope);

    $this->actingAs($scope['user'])
        ->postJson("/api/v1/inventory-procurement/department-stock-balances/{$scope['department']->id}/return", $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['item_id', 'quantity']);

    $this->actingAs($scope['user'])
        ->postJson("/api/v1/inventory-procurement/department-stock-balances/{$scope['department']->id}/wastage", $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['item_id', 'quantity']);
});

// ─── Department Lookup by Name ──────────────────────────────

it('resolves department UUID from appointment department name', function (): void {
    $scope = deptStockSetup(['inventory.procurement.read']);
    $department = DepartmentModel::create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'code' => 'DEPT-RAD',
        'name' => 'Radiology',
        'service_type' => 'clinical',
        'status' => 'active',
    ]);

    $repo = app(\App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface::class);
    $found = $repo->findActiveByName('Radiology');

    expect($found)->not->toBeNull();
    expect($found['id'])->toBe($department->id);

    $foundLower = $repo->findActiveByName('radiology');
    expect($foundLower['id'])->toBe($department->id);

    $notFound = $repo->findActiveByName('Nonexistent Department');
    expect($notFound)->toBeNull();
});

it('does not record department stock consumption when no department is linked', function (): void {
    $scope = deptStockSetup([
        'inventory.procurement.read',
        'inventory.procurement.manage-items',
        'pharmacy.orders.update-status',
        'pharmacy.orders.create',
    ]);
    $headers = deptStockHeaders($scope);

    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'catalog_type' => 'formulary_item',
        'code' => 'MED-IBU-400',
        'name' => 'Ibuprofen 400mg',
        'category' => 'analgesics',
        'unit' => 'tablet',
        'status' => 'active',
    ]);

    $item = deptStockItem($scope, [
        'item_code' => 'PHARM-NO-DEPT',
        'item_name' => 'Ibuprofen 400mg',
        'category' => 'pharmaceutical',
        'clinical_catalog_item_id' => $catalogItem->id,
    ]);

    $patientId = (string) Str::uuid();
    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT'.Str::upper(Str::random(6)),
        'first_name' => 'No',
        'last_name' => 'Department',
        'gender' => 'female',
        'date_of_birth' => '1985-05-15',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $appointmentId = (string) Str::uuid();
    DB::table('appointments')->insert([
        'id' => $appointmentId,
        'appointment_number' => 'APT'.Str::upper(Str::random(6)),
        'patient_id' => $patientId,
        'department' => null,
        'scheduled_at' => now()->subHour(),
        'duration_minutes' => 30,
        'status' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderId = (string) Str::uuid();
    DB::table('pharmacy_orders')->insert([
        'id' => $orderId,
        'order_number' => 'RX'.Str::upper(Str::random(6)),
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'medication_code' => 'PHARM-NO-DEPT',
        'medication_name' => 'Ibuprofen 400mg',
        'dosage_instruction' => '400mg as needed',
        'quantity_prescribed' => 5,
        'quantity_dispensed' => 0,
        'prescribed_unit' => 'box',
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'ordered_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($scope['user'])
        ->patchJson("/api/v1/pharmacy-orders/{$orderId}/status", array_merge(
            $headers,
            ['status' => 'in_preparation'],
        ))
        ->assertOk();

    $this->actingAs($scope['user'])
        ->patchJson("/api/v1/pharmacy-orders/{$orderId}/status", array_merge(
            $headers,
            ['status' => 'dispensed', 'quantity_dispensed' => 5],
        ))
        ->assertOk();

    $consumptionCount = DepartmentStockMovementModel::query()
        ->where('movement_type', 'consume')
        ->where('source', 'pharmacy_dispense')
        ->count();

    expect($consumptionCount)->toBe(0);
});
