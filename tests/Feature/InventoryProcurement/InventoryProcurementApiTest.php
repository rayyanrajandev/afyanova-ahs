<?php

use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemAuditLogModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryProcurementRequestAuditLogModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeInventoryProcurementUser(array $permissions = []): User
{
    return makeUserWithRole($permissions);
}

/**
 * @return array{tenantId:string,facilityId:string,tenantCode:string,facilityCode:string}
 */
function seedInventoryProcurementPlatformScope(
    User $user,
    string $tenantCode = 'TEN-INV',
    string $tenantName = 'Inventory Governance Tenant',
    string $countryCode = 'TZ',
    string $facilityCode = 'FAC-INV',
    string $facilityName = 'Inventory Governance Facility',
): array {
    $tenant = DB::table('tenants')->where('code', $tenantCode)->first();

    if ($tenant === null) {
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'code' => $tenantCode,
            'name' => $tenantName,
            'country_code' => $countryCode,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        $tenantId = (string) $tenant->id;
    }

    $facility = DB::table('facilities')
        ->where('tenant_id', $tenantId)
        ->where('code', $facilityCode)
        ->first();

    if ($facility === null) {
        $facilityId = (string) Str::uuid();
        DB::table('facilities')->insert([
            'id' => $facilityId,
            'tenant_id' => $tenantId,
            'code' => $facilityCode,
            'name' => $facilityName,
            'facility_type' => 'hospital',
            'timezone' => 'Africa/Dar_es_Salaam',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        $facilityId = (string) $facility->id;
    }

    if (! DB::table('facility_user')
        ->where('facility_id', $facilityId)
        ->where('user_id', $user->id)
        ->exists()) {
        DB::table('facility_user')->insert([
            'facility_id' => $facilityId,
            'user_id' => $user->id,
            'role' => 'inventory_manager',
            'is_primary' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return [
        'tenantId' => $tenantId,
        'facilityId' => $facilityId,
        'tenantCode' => $tenantCode,
        'facilityCode' => $facilityCode,
    ];
}

/**
 * @param  array{tenantCode:string,facilityCode:string}  $scope
 * @return array<string, string>
 */
function inventoryProcurementScopeHeaders(array $scope): array
{
    return [
        'X-Tenant-Code' => $scope['tenantCode'],
        'X-Facility-Code' => $scope['facilityCode'],
    ];
}

/**
 * @return array<string, mixed>
 */
function createInventoryItem(User $user, array $overrides = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/items', array_merge([
            'itemCode' => 'ITM-'.strtoupper(Str::random(8)),
            'itemName' => 'Surgical Gloves',
            'category' => 'medical_consumable',
            'unit' => 'box',
            'reorderLevel' => 12,
            'maxStockLevel' => 250,
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

function createApprovedMedicineCatalogItem(array $overrides = []): ClinicalCatalogItemModel
{
    return ClinicalCatalogItemModel::query()->create(array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'formulary_item',
        'code' => 'MED-'.strtoupper(Str::random(8)),
        'name' => 'Paracetamol 500mg',
        'department_id' => null,
        'category' => 'analgesics',
        'unit' => 'tablet',
        'description' => 'Approved medicine fixture for inventory receipt tests.',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function createPharmaceuticalInventoryItem(User $user, array $overrides = []): array
{
    $catalogItem = createApprovedMedicineCatalogItem([
        'code' => $overrides['catalogCode'] ?? 'MED-'.strtoupper(Str::random(8)),
        'name' => $overrides['catalogName'] ?? ($overrides['itemName'] ?? 'Amoxicillin 500mg'),
        'category' => $overrides['catalogCategory'] ?? 'antibiotics',
        'unit' => $overrides['catalogUnit'] ?? ($overrides['dispensingUnit'] ?? 'capsule'),
    ]);

    unset($overrides['catalogCode'], $overrides['catalogName'], $overrides['catalogCategory'], $overrides['catalogUnit']);

    return createInventoryItem($user, array_merge([
        'clinicalCatalogItemId' => $catalogItem->id,
        'itemCode' => 'PHARM-'.strtoupper(Str::random(8)),
        'itemName' => $catalogItem->name,
        'category' => 'pharmaceutical',
        'unit' => 'box',
        'dispensingUnit' => 'capsule',
        'conversionFactor' => 10,
        'genericName' => 'Amoxicillin',
        'dosageForm' => 'capsule',
        'strength' => '500 mg',
        'storageConditions' => 'room_temperature_controlled',
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function createInventoryWarehouse(User $user, array $overrides = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/warehouses', array_merge([
            'warehouseCode' => 'WH-'.strtoupper(Str::random(8)),
            'warehouseName' => 'Main Stores '.Str::upper(Str::random(4)),
            'warehouseType' => 'central',
            'location' => 'Building A',
            'contactPerson' => 'Store Supervisor',
            'phone' => '+255711000000',
            'email' => 'warehouse-'.strtolower(Str::random(6)).'@example.test',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

it('returns category metadata in inventory reference data', function (): void {
    $user = makeInventoryProcurementUser(['inventory.procurement.read']);

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/reference-data')
        ->assertOk()
        ->assertJsonPath('categories.pharmaceutical', 'Pharmaceutical')
        ->assertJsonFragment([
            'value' => 'blood_product',
            'template' => 'expiry_sensitive',
            'requiresExpiryTracking' => true,
            'requiresColdChain' => true,
            'controlledSubstanceEligible' => false,
        ])
        ->assertJsonFragment([
            'value' => 'refrigerated_2_8c',
            'label' => 'Refrigerated (2–8°C)',
        ])
        ->assertJsonFragment([
            'value' => 'schedule_II',
            'label' => 'Schedule II',
        ]);
});

it('enforces category-specific create validation for cold-chain and expiry-sensitive inventory', function (): void {
    $user = makeInventoryProcurementUser(['inventory.procurement.manage-items']);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'ITM-'.strtoupper(Str::random(8)),
            'itemName' => 'Whole Blood Unit',
            'category' => 'blood_product',
            'unit' => 'bag',
            'requiresColdChain' => false,
            'storageConditions' => null,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['requiresColdChain', 'storageConditions']);
});

it('enforces category-specific update validation using the stored item category when category is omitted', function (): void {
    $user = makeInventoryProcurementUser(['inventory.procurement.manage-items', 'inventory.procurement.manage-warehouses']);
    $warehouse = createInventoryWarehouse($user);

    $item = createInventoryItem($user, [
        'itemName' => 'Whole Blood Unit',
        'category' => 'blood_product',
        'unit' => 'bag',
        'requiresColdChain' => true,
        'storageConditions' => 'refrigerated_2_8c',
        'defaultWarehouseId' => $warehouse['id'],
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/items/'.$item['id'], [
            'requiresColdChain' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['requiresColdChain']);
});

// is_controlled_substance/controlled_substance_schedule were dropped from
// inventory_items in Phase 3 (Inventory_MasterData_Alignment_Plan.md) --
// Pharmaceutical inventory always requires a Clinical Catalog link, so that's
// now the only place this is validated/persisted. Regression coverage for the
// bug this replaced: StoreInventoryItemRequest/UpdateInventoryItemRequest used
// to validate and compliance-gate these fields even though the use cases never
// wrote them anywhere -- a silent no-op that looked like it worked.
it('accepts and silently ignores isControlledSubstance/controlledSubstanceSchedule on inventory item writes', function (): void {
    $user = makeInventoryProcurementUser(['inventory.procurement.manage-items', 'inventory.procurement.manage-warehouses']);
    $warehouse = createInventoryWarehouse($user);

    $item = createInventoryItem($user, [
        'itemName' => 'Sterile Gauze Pads',
        'category' => 'medical_consumable',
        'defaultWarehouseId' => $warehouse['id'],
        'isControlledSubstance' => true,
        'controlledSubstanceSchedule' => 'schedule_II',
    ]);

    expect($item['isControlledSubstance'] ?? null)->toBeFalsy();

    $updated = $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/items/'.$item['id'], [
            'binLocation' => 'B-02-04',
            'isControlledSubstance' => true,
            'controlledSubstanceSchedule' => 'schedule_II',
        ])
        ->assertOk()
        ->json('data');

    expect($updated['binLocation'])->toBe('B-02-04');
    expect($updated['isControlledSubstance'] ?? null)->toBeFalsy();
});

// Inventory_MasterData_Alignment_Plan.md Phase 9: conversionFactor used to be
// writable on update regardless of catalog link, unlike its siblings
// (item_name/unit/dispensing_unit/codes/subcategory), which always re-sync from
// the catalog. That let an inventory item silently drift from what Clinical
// Catalog says about the same drug's packaging ratio. Now it follows the same
// rule: the catalog wins whenever it has an opinion, and only acts as a
// per-item fallback when the catalog doesn't define one yet.
it('locks conversionFactor to the catalog value on update once the catalog defines one, but allows it as a fallback when the catalog has none', function (): void {
    $user = makeInventoryProcurementUser(['inventory.procurement.manage-items', 'inventory.procurement.manage-warehouses']);
    $warehouse = createInventoryWarehouse($user);

    $catalogItemWithFactor = createApprovedMedicineCatalogItem([
        'name' => 'Amoxicillin 500mg',
        'category' => 'antibiotics',
        'unit' => 'capsule',
        'metadata' => ['conversionFactor' => 100],
    ]);

    $item = createInventoryItem($user, [
        'clinicalCatalogItemId' => $catalogItemWithFactor->id,
        'itemCode' => 'PHARM-'.Str::upper(Str::random(8)),
        'itemName' => $catalogItemWithFactor->name,
        'category' => 'pharmaceutical',
        'unit' => 'box',
        'conversionFactor' => 5,
        'storageConditions' => 'room_temperature',
        'defaultWarehouseId' => $warehouse['id'],
    ]);
    expect((float) $item['conversionFactor'])->toBe(100.0);

    $updated = $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/items/'.$item['id'], [
            'binLocation' => 'B-05-01',
            'conversionFactor' => 50,
        ])
        ->assertOk()
        ->json('data');

    expect($updated['binLocation'])->toBe('B-05-01');
    expect((float) $updated['conversionFactor'])->toBe(100.0);

    // Fallback: a catalog item with no conversionFactor opinion lets the payload through.
    $catalogItemWithoutFactor = createApprovedMedicineCatalogItem([
        'name' => 'Ceftriaxone 1g',
        'category' => 'antibiotics',
        'unit' => 'vial',
        'metadata' => null,
    ]);

    $gapItem = createInventoryItem($user, [
        'clinicalCatalogItemId' => $catalogItemWithoutFactor->id,
        'itemCode' => 'PHARM-'.Str::upper(Str::random(8)),
        'itemName' => $catalogItemWithoutFactor->name,
        'category' => 'pharmaceutical',
        'unit' => 'box',
        'storageConditions' => 'room_temperature',
        'defaultWarehouseId' => $warehouse['id'],
    ]);

    $gapUpdated = $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/items/'.$gapItem['id'], [
            'conversionFactor' => 30,
        ])
        ->assertOk()
        ->json('data');

    expect((float) $gapUpdated['conversionFactor'])->toBe(30.0);
});

// Inventory_MasterData_Alignment_Plan.md Phase 6: manage-items alone is not enough to
// enable cold-chain for a category that doesn't mandate it (Laboratory supports storage
// fields but Blood Product is the only one that requires cold chain) -- that needs
// manage-compliance. Regression coverage for a bug caught while building the frontend
// gate: buildItemPayload() resends every field on every save, so a naive "reject if
// present and true" check would block routine edits to an already-flagged item.
it('gates enabling cold chain for a non-mandatory category behind the compliance permission, without blocking unrelated edits', function (): void {
    $complianceUser = makeInventoryProcurementUser(['inventory.procurement.manage-items', 'inventory.procurement.manage-compliance', 'inventory.procurement.manage-warehouses']);
    $warehouse = createInventoryWarehouse($complianceUser);

    $reagent = createInventoryItem($complianceUser, [
        'itemName' => 'Cold-Stored Reagent',
        'category' => 'laboratory',
        'unit' => 'bottle',
        'requiresColdChain' => true,
        'storageConditions' => 'refrigerated_2_8c',
        'defaultWarehouseId' => $warehouse['id'],
    ]);

    $itemsOnlyUser = makeInventoryProcurementUser(['inventory.procurement.manage-items']);

    // Editing an unrelated field while resending the existing true value: allowed.
    $updated = $this->actingAs($itemsOnlyUser)
        ->patchJson('/api/v1/inventory-procurement/items/'.$reagent['id'], [
            'binLocation' => 'B-02-04',
            'requiresColdChain' => true,
            'storageConditions' => 'refrigerated_2_8c',
        ])
        ->assertOk()
        ->json('data');

    expect($updated['binLocation'])->toBe('B-02-04');
    expect($updated['requiresColdChain'])->toBeTrue();

    // Actually flipping it off still requires the permission.
    $this->actingAs($itemsOnlyUser)
        ->patchJson('/api/v1/inventory-procurement/items/'.$reagent['id'], [
            'requiresColdChain' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['requiresColdChain']);

    // And enabling it from scratch on a category that doesn't mandate it also requires it.
    $freshReagent = createInventoryItem($complianceUser, [
        'itemName' => 'Room-Temp Reagent',
        'category' => 'laboratory',
        'unit' => 'bottle',
        'requiresColdChain' => false,
        'storageConditions' => 'cool_dry_place',
        'defaultWarehouseId' => $warehouse['id'],
    ]);

    $this->actingAs($itemsOnlyUser)
        ->patchJson('/api/v1/inventory-procurement/items/'.$freshReagent['id'], [
            'requiresColdChain' => true,
            'storageConditions' => 'refrigerated_2_8c',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['requiresColdChain']);
});

it('filters inventory item lookup by category and subcategory together', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.read',
        'inventory.procurement.manage-items',
        'inventory.procurement.manage-warehouses',
    ]);

    $matched = createInventoryItem($user, [
        'itemCode' => 'LAB-URINE-001',
        'itemName' => 'Urinalysis Strips',
        'category' => 'laboratory',
        'subcategory' => 'rapid_tests',
        'unit' => 'bottle',
        'storageConditions' => 'room_temperature_controlled',
    ]);

    createInventoryItem($user, [
        'itemCode' => 'LAB-CBC-001',
        'itemName' => 'CBC Reagent Kit',
        'category' => 'laboratory',
        'subcategory' => 'hematology',
        'unit' => 'kit',
        'storageConditions' => 'refrigerated_2_8c',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/items?category=laboratory&subcategory=rapid_tests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $matched['id'])
        ->assertJsonPath('data.0.subcategory', 'rapid_tests');
});

it('exposes item movement counts so the frontend can distinguish opening stock setup', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.read',
        'inventory.procurement.manage-items',
    ]);

    $item = createInventoryItem($user, [
        'itemCode' => 'OPEN-STOCK-001',
        'itemName' => 'Opening Stock Test Item',
        'category' => 'medical_consumable',
        'unit' => 'box',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/items?q=OPEN-STOCK-001')
        ->assertOk()
        ->assertJsonPath('data.0.id', $item['id'])
        ->assertJsonPath('data.0.movementCount', 0);

    InventoryStockMovementModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'item_id' => $item['id'],
        'movement_type' => 'receive',
        'adjustment_direction' => null,
        'quantity' => 10,
        'quantity_delta' => 10,
        'stock_before' => 0,
        'stock_after' => 10,
        'reason' => 'Opening balance',
        'notes' => 'Day-0 stock load.',
        'actor_id' => $user->id,
        'metadata' => ['source' => 'manual_entry'],
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/items?q=OPEN-STOCK-001')
        ->assertOk()
        ->assertJsonPath('data.0.id', $item['id'])
        ->assertJsonPath('data.0.movementCount', 1);
});

it('searches inventory items case-insensitively with partial names', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.read',
        'inventory.procurement.manage-items',
    ]);

    $scope = seedInventoryProcurementPlatformScope($user);
    $headers = inventoryProcurementScopeHeaders($scope);

    $albendazole = test()->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'MED-ALB-400',
            'itemName' => 'Albendazole 400mg Tablet',
            'category' => 'medical_consumable',
            'unit' => 'box',
            'reorderLevel' => 12,
            'maxStockLevel' => 250,
        ])
        ->assertCreated()
        ->json('data');

    test()->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'MED-PARA-500',
            'itemName' => 'Paracetamol 500mg Tablet',
            'category' => 'medical_consumable',
            'unit' => 'box',
            'reorderLevel' => 12,
            'maxStockLevel' => 250,
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/inventory-procurement/items?q=albe')
        ->assertOk()
        ->assertJsonPath('data.0.id', $albendazole['id']);

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/inventory-procurement/items?q=ALBENDA')
        ->assertOk()
        ->assertJsonPath('data.0.itemName', 'Albendazole 400mg Tablet');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/inventory-procurement/items?q=al')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

/**
 * @return array<string, mixed>
 */
function createProcurementRequest(User $user, string $itemId, array $overrides = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/procurement-requests', array_merge([
            'itemId' => $itemId,
            'requestedQuantity' => 20,
            'unitCostEstimate' => 1500,
            'supplierName' => 'MedSupply',
            'notes' => 'Routine replenishment',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

function approveAndOrderProcurementRequest(User $user, string $requestId, array $overrides = []): void
{
    test()->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/procurement-requests/'.$requestId.'/status', [
            'status' => 'approved',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    test()->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/procurement-requests/'.$requestId.'/place-order', array_merge([
            'purchaseOrderNumber' => 'PO-INV-'.strtoupper(Str::random(6)),
            'orderedQuantity' => 20,
            'unitCostEstimate' => 1500,
            'notes' => 'Supplier confirmed delivery for stock replenishment.',
        ], $overrides))
        ->assertOk()
        ->assertJsonPath('data.status', 'ordered');
}

it('writes inventory item status parity metadata and rejects status fields on item detail update', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.view-audit-logs',
    ]);

    $item = createInventoryItem($user);

    $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/items/'.$item['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Temporarily unavailable supplier contract',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.statusReason', 'Temporarily unavailable supplier contract');

    $statusAudit = InventoryItemAuditLogModel::query()
        ->where('inventory_item_id', $item['id'])
        ->where('action', 'inventory-item.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();

    $metadata = $statusAudit?->metadata ?? [];
    expect($metadata['transition'] ?? [])->toMatchArray([
        'from' => 'active',
        'to' => 'inactive',
    ]);
    expect($metadata)->toMatchArray([
        'reason_required' => true,
        'reason_provided' => true,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/items/'.$item['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'inventory-item.status.updated');

    $response = $this->actingAs($user)
        ->get('/api/v1/inventory-procurement/items/'.$item['id'].'/audit-logs/export');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');

    $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/items/'.$item['id'], [
            'itemName' => 'Surgical Gloves Updated',
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('writes procurement request status parity metadata and enforces manual-status endpoint boundaries', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-request',
        'inventory.procurement.update-request-status',
        'inventory.procurement.view-audit-logs',
    ]);

    $item = createInventoryItem($user);
    $request = createProcurementRequest($user, (string) $item['id']);

    $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/procurement-requests/'.$request['id'].'/status', [
            'status' => 'approved',
            'reason' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $statusAudit = InventoryProcurementRequestAuditLogModel::query()
        ->where('inventory_procurement_request_id', $request['id'])
        ->where('action', 'inventory-procurement-request.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();

    $metadata = $statusAudit?->metadata ?? [];
    expect($metadata['transition'] ?? [])->toMatchArray([
        'from' => 'pending_approval',
        'to' => 'approved',
    ]);
    expect($metadata)->toMatchArray([
        'reason_required' => false,
        'reason_provided' => false,
        'approval_timestamp_required' => true,
        'approval_timestamp_provided' => true,
        'approved_by_required' => true,
        'approved_by_provided' => true,
        'order_timestamp_required' => false,
        'order_timestamp_provided' => false,
        'receipt_timestamp_required' => false,
        'receipt_timestamp_provided' => false,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/procurement-requests/'.$request['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'inventory-procurement-request.status.updated');

    $response = $this->actingAs($user)
        ->get('/api/v1/inventory-procurement/procurement-requests/'.$request['id'].'/audit-logs/export');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');

    $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/procurement-requests/'.$request['id'].'/status', [
            'status' => 'ordered',
            'reason' => null,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('writes procurement order and receive parity metadata in audit logs', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-request',
        'inventory.procurement.update-request-status',
        'inventory.procurement.create-movement',
        'inventory.procurement.read',
    ]);

    $item = createInventoryItem($user);
    $request = createProcurementRequest($user, (string) $item['id'], [
        'requestedQuantity' => 18,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/procurement-requests/'.$request['id'].'/status', [
            'status' => 'approved',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/procurement-requests/'.$request['id'].'/place-order', [
            'purchaseOrderNumber' => 'PO-INV-HARDENING-001',
            'orderedQuantity' => 18,
            'unitCostEstimate' => 1550,
            'notes' => 'Confirmed with supplier for urgent restock.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'ordered');

    $orderedAudit = InventoryProcurementRequestAuditLogModel::query()
        ->where('inventory_procurement_request_id', $request['id'])
        ->where('action', 'inventory-procurement-request.ordered')
        ->latest('created_at')
        ->first();

    expect($orderedAudit)->not->toBeNull();

    $orderedMetadata = $orderedAudit?->metadata ?? [];
    expect($orderedMetadata['transition'] ?? [])->toMatchArray([
        'from' => 'approved',
        'to' => 'ordered',
    ]);
    expect($orderedMetadata)->toMatchArray([
        'workflow_status_required' => 'approved',
        'workflow_status_satisfied' => true,
        'purchase_order_required' => true,
        'purchase_order_provided' => true,
        'ordered_quantity_required' => true,
        'ordered_quantity_provided' => true,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/procurement-requests/'.$request['id'].'/receive', [
            'receivedQuantity' => 18,
            'receivedUnitCost' => 1575,
            'reason' => 'Shipment received and verified.',
            'notes' => 'All cartons received in good condition.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'received');

    $receivedAudit = InventoryProcurementRequestAuditLogModel::query()
        ->where('inventory_procurement_request_id', $request['id'])
        ->where('action', 'inventory-procurement-request.received')
        ->latest('created_at')
        ->first();

    expect($receivedAudit)->not->toBeNull();

    $receivedMetadata = $receivedAudit?->metadata ?? [];
    expect($receivedMetadata['transition'] ?? [])->toMatchArray([
        'from' => 'ordered',
        'to' => 'received',
    ]);
    expect($receivedMetadata)->toMatchArray([
        'workflow_status_required' => 'ordered',
        'workflow_status_satisfied' => true,
        'received_quantity_submitted' => 18.0,
        'received_quantity_within_ordered' => true,
        'received_timestamp_required' => true,
        'received_timestamp_provided' => true,
        'received_unit_cost_provided' => true,
    ]);
    expect(($receivedMetadata['stockMovementId'] ?? null))->not->toBeNull();

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/stock-movements?sourceKey=procurement_receipt')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.sourceKey', 'procurement_receipt')
        ->assertJsonPath('data.0.sourceLabel', 'Procurement receipt')
        ->assertJsonPath('data.0.sourceReference', $request['requestNumber'] ?? null);
});

it('creates a tracked batch and linked receipt movement for pharmaceutical procurement receipts', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-request',
        'inventory.procurement.update-request-status',
        'inventory.procurement.create-movement',
        'inventory.procurement.read',
    ]);

    $item = createPharmaceuticalInventoryItem($user, [
        'itemName' => 'Amoxicillin 500mg',
        'genericName' => 'Amoxicillin',
        'unit' => 'box',
        'dispensingUnit' => 'capsule',
        'conversionFactor' => 10,
    ]);

    $request = createProcurementRequest($user, (string) $item['id'], [
        'requestedQuantity' => 30,
    ]);

    approveAndOrderProcurementRequest($user, (string) $request['id'], [
        'purchaseOrderNumber' => 'PO-RX-RECEIVE-001',
        'orderedQuantity' => 30,
        'unitCostEstimate' => 1850,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/procurement-requests/'.$request['id'].'/receive', [
            'receivedQuantity' => 30,
            'receivedUnitCost' => 1875,
            'batchNumber' => 'rx-2026-001',
            'lotNumber' => 'LOT-RX-001',
            'manufactureDate' => '2026-01-10',
            'expiryDate' => '2028-01-09',
            'binLocation' => 'A-01-04',
            'reason' => 'Initial supplier delivery',
            'notes' => 'All cartons sealed and verified.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.itemCategory', 'pharmaceutical')
        ->assertJsonPath('data.itemName', 'Amoxicillin 500mg');

    $batch = DB::table('inventory_batches')
        ->where('item_id', $item['id'])
        ->where('batch_number', 'RX-2026-001')
        ->first();

    expect($batch)->not->toBeNull();
    expect((float) $batch->quantity)->toBe(30.0);
    expect($batch->lot_number)->toBe('LOT-RX-001');
    expect((string) $batch->expiry_date)->toContain('2028-01-09');

    $movement = InventoryStockMovementModel::query()
        ->where('procurement_request_id', $request['id'])
        ->latest('created_at')
        ->first();

    expect($movement)->not->toBeNull();
    expect($movement?->movement_type)->toBe('receive');
    expect($movement?->batch_id)->toBe($batch->id);
    expect($movement?->stock_before)->toBe('0.000');
    expect($movement?->stock_after)->toBe('30.000');
    expect($movement?->metadata['batchMode'] ?? null)->toBe('tracked');
    expect($movement?->metadata['batchNumber'] ?? null)->toBe('RX-2026-001');
});

it('requires batch and expiry details for laboratory reagent procurement receipts', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-request',
        'inventory.procurement.update-request-status',
        'inventory.procurement.create-movement',
    ]);

    $item = createInventoryItem($user, [
        'itemCode' => 'LAB-REAG-001',
        'itemName' => 'CBC Reagent Kit',
        'category' => 'laboratory',
        'unit' => 'kit',
        'storageConditions' => 'room_temperature_controlled',
    ]);

    $request = createProcurementRequest($user, (string) $item['id'], [
        'requestedQuantity' => 12,
    ]);

    approveAndOrderProcurementRequest($user, (string) $request['id'], [
        'purchaseOrderNumber' => 'PO-LAB-RECEIVE-001',
        'orderedQuantity' => 12,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/procurement-requests/'.$request['id'].'/receive', [
            'receivedQuantity' => 12,
            'receivedUnitCost' => 9500,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['batchNumber']);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/procurement-requests/'.$request['id'].'/receive', [
            'receivedQuantity' => 12,
            'receivedUnitCost' => 9500,
            'batchNumber' => 'LAB-2026-001',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['expiryDate']);
});

it('keeps non-expiry consumable procurement receipts untracked when no batch flow is required', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-request',
        'inventory.procurement.update-request-status',
        'inventory.procurement.create-movement',
    ]);

    $item = createInventoryItem($user, [
        'itemCode' => 'CONS-001',
        'itemName' => 'Examination Gloves',
        'category' => 'medical_consumable',
        'unit' => 'box',
    ]);

    $request = createProcurementRequest($user, (string) $item['id'], [
        'requestedQuantity' => 40,
    ]);

    approveAndOrderProcurementRequest($user, (string) $request['id'], [
        'purchaseOrderNumber' => 'PO-CONS-001',
        'orderedQuantity' => 40,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/procurement-requests/'.$request['id'].'/receive', [
            'receivedQuantity' => 40,
            'receivedUnitCost' => 4200,
            'reason' => 'Routine stores delivery',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.itemCategory', 'medical_consumable');

    expect(DB::table('inventory_batches')->where('item_id', $item['id'])->count())->toBe(0);

    $movement = InventoryStockMovementModel::query()
        ->where('procurement_request_id', $request['id'])
        ->latest('created_at')
        ->first();

    expect($movement)->not->toBeNull();
    expect($movement?->batch_id)->toBeNull();
    expect($movement?->metadata['batchMode'] ?? null)->toBe('untracked');
});

it('tops up the existing tracked batch when later procurement receipts use the same batch number', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-request',
        'inventory.procurement.update-request-status',
        'inventory.procurement.create-movement',
    ]);

    $item = createPharmaceuticalInventoryItem($user, [
        'itemName' => 'Ceftriaxone 1g',
        'genericName' => 'Ceftriaxone',
        'dispensingUnit' => 'vial',
        'unit' => 'box',
        'conversionFactor' => 10,
    ]);

    $firstRequest = createProcurementRequest($user, (string) $item['id'], [
        'requestedQuantity' => 10,
    ]);

    approveAndOrderProcurementRequest($user, (string) $firstRequest['id'], [
        'purchaseOrderNumber' => 'PO-BATCH-001',
        'orderedQuantity' => 10,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/procurement-requests/'.$firstRequest['id'].'/receive', [
            'receivedQuantity' => 10,
            'receivedUnitCost' => 2400,
            'batchNumber' => 'CEF-2026-001',
            'lotNumber' => 'LOT-CEF-001',
            'manufactureDate' => '2026-02-01',
            'expiryDate' => '2028-01-31',
        ])
        ->assertOk();

    $secondRequest = createProcurementRequest($user, (string) $item['id'], [
        'requestedQuantity' => 5,
    ]);

    approveAndOrderProcurementRequest($user, (string) $secondRequest['id'], [
        'purchaseOrderNumber' => 'PO-BATCH-002',
        'orderedQuantity' => 5,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/procurement-requests/'.$secondRequest['id'].'/receive', [
            'receivedQuantity' => 5,
            'receivedUnitCost' => 2425,
            'batchNumber' => 'CEF-2026-001',
            'lotNumber' => 'LOT-CEF-001',
            'manufactureDate' => '2026-02-01',
            'expiryDate' => '2028-01-31',
        ])
        ->assertOk();

    $batches = DB::table('inventory_batches')
        ->where('item_id', $item['id'])
        ->get();

    expect($batches)->toHaveCount(1);
    expect((float) $batches->first()->quantity)->toBe(15.0);

    $latestMovement = InventoryStockMovementModel::query()
        ->where('procurement_request_id', $secondRequest['id'])
        ->latest('created_at')
        ->first();

    expect($latestMovement)->not->toBeNull();
    expect($latestMovement?->batch_id)->toBe($batches->first()->id);
});

it('exports stock movement ledger csv with branded export headers', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.reconcile-stock',
        'inventory.procurement.read',
    ]);

    $item = createInventoryItem($user, [
        'itemCode' => 'LEDGER-001',
        'itemName' => 'Sterile Gauze Pads',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'adjust',
            'adjustmentDirection' => 'increase',
            'quantity' => 24,
            'reason' => 'Opening stock intake',
            'notes' => 'Initial stock count posted to stores ledger.',
            'metadata' => [
                'batchNumber' => 'BATCH-LEDGER-001',
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.itemId', $item['id']);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/stock-movements/reconcile', [
            'itemId' => $item['id'],
            'countedStock' => 21,
            'reason' => 'Monthly shelf count reconciliation',
            'notes' => 'Three packs consumed during emergency call.',
            'sessionReference' => 'STOCK-COUNT-001',
        ])
        ->assertCreated();

    $response = $this->actingAs($user)
        ->get('/api/v1/inventory-procurement/stock-movements/export')
        ->assertOk()
        ->assertHeader('X-Inventory-Stock-Ledger-CSV-Schema-Version', 'inventory-stock-ledger-csv.v1')
        ->assertHeader('X-Export-System-Name', 'Afyanova AHS')
        ->assertHeader('X-Export-System-Slug', 'afyanova_ahs');

    $csv = $response->streamedContent();
    expect($csv)->toContain('occurredAt,movementType,adjustmentDirection,itemId,itemCode,itemName,sourceKey,sourceLabel,sourceReference,sourceDetail,sourceType,sourceId,procurementRequestId');
    expect($csv)->toContain('LEDGER-001');
    expect($csv)->toContain('manual_entry,"Manual ledger entry"');
    expect($csv)->toContain('stock_reconciliation,"Stock reconciliation",STOCK-COUNT-001');
    expect($csv)->toContain('STOCK-COUNT-001');
});

it('surfaces clinical consumption source context and filters stock ledger movements by source', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $warehouse = createInventoryWarehouse($user, [
        'warehouseName' => 'Chemistry Bench Store',
        'warehouseCode' => 'WH-CHEM-001',
    ]);

    $item = createInventoryItem($user, [
        'itemCode' => 'LEDGER-SRC-001',
        'itemName' => 'CBC Reagent Kit',
        'category' => 'laboratory',
        'unit' => 'kit',
        'storageConditions' => 'room_temperature_controlled',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'adjust',
            'adjustmentDirection' => 'increase',
            'destinationWarehouseId' => $warehouse['id'],
            'quantity' => 12,
            'batchNumber' => 'CBC-OPEN-001',
            'expiryDate' => '2028-12-31',
            'reason' => 'Opening balance',
            'notes' => 'Manual ledger opening balance for chemistry bench.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.sourceKey', 'manual_entry')
        ->assertJsonPath('data.sourceLabel', 'Manual ledger entry');

    InventoryStockMovementModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'item_id' => $item['id'],
        'source_type' => 'laboratory_order',
        'source_id' => (string) Str::uuid(),
        'clinical_catalog_item_id' => (string) Str::uuid(),
        'consumption_recipe_item_id' => (string) Str::uuid(),
        'movement_type' => 'issue',
        'adjustment_direction' => null,
        'quantity' => 2,
        'quantity_delta' => -2,
        'stock_before' => 12,
        'stock_after' => 10,
        'reason' => 'Automated clinical consumption',
        'notes' => 'CBC kit consumed on result completion.',
        'actor_id' => null,
        'metadata' => [
            'source' => 'clinical_catalog_consumption_recipe',
            'catalogType' => 'lab_test',
            'consumptionStage' => 'result_completion',
            'sourceSnapshot' => [
                'order_number' => 'LAB-2026-0001',
            ],
        ],
        'occurred_at' => now()->subMinute(),
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/stock-movements?sourceKey=clinical_consumption')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.sourceKey', 'clinical_consumption')
        ->assertJsonPath('data.0.sourceLabel', 'Lab test completion')
        ->assertJsonPath('data.0.sourceReference', 'LAB-2026-0001')
        ->assertJsonPath('data.0.sourceDetail', 'Lab tests | Stage: Result Completion');

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/stock-movements?sourceKey=manual_entry')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.sourceKey', 'manual_entry')
        ->assertJsonPath('data.0.sourceLabel', 'Manual ledger entry');

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/stock-movements?sourceKey=clinical_consumption&q=LAB-2026-0001')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.sourceReference', 'LAB-2026-0001');

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/stock-movements/summary?sourceKey=clinical_consumption')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.issue', 1);
});

it('requires an exact batch for tracked manual stock issues and deducts the selected batch only', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $warehouse = createInventoryWarehouse($user, [
        'warehouseName' => 'Pharmacy Main Store',
        'warehouseCode' => 'WH-PHARM-001',
    ]);

    $item = createPharmaceuticalInventoryItem($user, [
        'itemCode' => 'RX-MAN-001',
        'itemName' => 'Paracetamol 500mg',
        'defaultWarehouseId' => $warehouse['id'],
    ]);

    $receiveResponse = $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $warehouse['id'],
            'quantity' => 20,
            'batchNumber' => 'PCM-2026-001',
            'lotNumber' => 'LOT-PCM-001',
            'manufactureDate' => '2026-01-01',
            'expiryDate' => '2028-01-01',
            'binLocation' => 'RACK-A1',
            'reason' => 'Opening stock',
        ])
        ->assertCreated();

    expect($receiveResponse->json('data.batchId'))->not->toBeNull();

    $batch = DB::table('inventory_batches')
        ->where('item_id', $item['id'])
        ->where('batch_number', 'PCM-2026-001')
        ->first();

    expect($batch)->not->toBeNull();

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'issue',
            'sourceWarehouseId' => $warehouse['id'],
            'quantity' => 5,
            'reason' => 'Ward issue',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['batchId']);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'issue',
            'sourceWarehouseId' => $warehouse['id'],
            'batchId' => $batch->id,
            'quantity' => 5,
            'reason' => 'Ward issue',
        ])
        ->assertCreated()
        ->assertJsonPath('data.batchId', $batch->id)
        ->assertJsonPath('data.movementType', 'issue')
        ->assertJsonPath('data.metadata.issuePolicy', 'exact_batch');

    expect((float) DB::table('inventory_batches')->where('id', $batch->id)->value('quantity'))->toBe(15.0);
    expect((float) DB::table('inventory_items')->where('id', $item['id'])->value('current_stock'))->toBe(15.0);
});

it('requires batch-aware reconciliation for tracked inventory and posts a scoped batch adjustment', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.reconcile-stock',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $warehouse = createInventoryWarehouse($user, [
        'warehouseName' => 'Laboratory Main Store',
        'warehouseCode' => 'WH-LAB-001',
    ]);

    $item = createInventoryItem($user, [
        'itemCode' => 'LAB-REC-001',
        'itemName' => 'CBC Reagent Kit',
        'category' => 'laboratory',
        'unit' => 'kit',
        'defaultWarehouseId' => $warehouse['id'],
        'storageConditions' => 'room_temperature_controlled',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $warehouse['id'],
            'quantity' => 12,
            'batchNumber' => 'CBC-2026-001',
            'lotNumber' => 'LOT-CBC-001',
            'expiryDate' => '2027-12-31',
            'reason' => 'Initial reagent stock',
        ])
        ->assertCreated();

    $batch = DB::table('inventory_batches')
        ->where('item_id', $item['id'])
        ->where('batch_number', 'CBC-2026-001')
        ->first();

    expect($batch)->not->toBeNull();

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/stock-movements/reconcile', [
            'itemId' => $item['id'],
            'countedStock' => 10,
            'reason' => 'Cycle count',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['batchId']);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/stock-movements/reconcile', [
            'itemId' => $item['id'],
            'batchId' => $batch->id,
            'countedBatchQuantity' => 10,
            'reason' => 'Cycle count',
            'sessionReference' => 'LAB-COUNT-001',
        ])
        ->assertCreated()
        ->assertJsonPath('data.batchId', $batch->id)
        ->assertJsonPath('data.metadata.scope', 'batch')
        ->assertJsonPath('data.metadata.batchNumber', 'CBC-2026-001');

    expect((float) DB::table('inventory_batches')->where('id', $batch->id)->value('quantity'))->toBe(10.0);
    expect((float) DB::table('inventory_items')->where('id', $item['id'])->value('current_stock'))->toBe(10.0);
});

it('keeps warehouse transfers batch-safe from creation through dispatch and receipt', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $scope = seedInventoryProcurementPlatformScope(
        user: $user,
        tenantCode: 'TZ-INV-TRF',
        tenantName: 'Inventory Transfer Tenant',
        facilityCode: 'DAR-INV-TRF',
        facilityName: 'Inventory Transfer Facility',
    );
    $headers = inventoryProcurementScopeHeaders($scope);

    $sourceWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-CENTRAL-001',
            'warehouseName' => 'Central Medical Store',
        ])
        ->assertCreated()
        ->json('data');

    $destinationWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-WARD-001',
            'warehouseName' => 'Ward Satellite Store',
        ])
        ->assertCreated()
        ->json('data');

    $catalogItem = createApprovedMedicineCatalogItem([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'code' => 'MED-AMOX-TRF-001',
        'name' => 'Amoxicillin 500mg',
        'category' => 'antibiotics',
        'unit' => 'capsule',
    ]);

    $item = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'clinicalCatalogItemId' => $catalogItem->id,
            'itemCode' => 'RX-TRF-001',
            'itemName' => 'Amoxicillin 500mg',
            'category' => 'pharmaceutical',
            'unit' => 'box',
            'dispensingUnit' => 'capsule',
            'defaultWarehouseId' => $sourceWarehouse['id'],
            'storageConditions' => 'room_temperature_controlled',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $sourceWarehouse['id'],
            'quantity' => 20,
            'batchNumber' => 'AMOX-2026-001',
            'lotNumber' => 'LOT-AMOX-001',
            'manufactureDate' => '2026-02-01',
            'expiryDate' => '2028-02-01',
            'reason' => 'Opening stock',
        ])
        ->assertCreated();

    $batch = DB::table('inventory_batches')
        ->where('item_id', $item['id'])
        ->where('batch_number', 'AMOX-2026-001')
        ->first();

    expect($batch)->not->toBeNull();

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Ward replenishment',
            'lines' => [[
                'itemId' => $item['id'],
                'requestedQuantity' => 6,
                'unit' => 'box',
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.batchId']);

    $transfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Ward replenishment',
            'lines' => [[
                'itemId' => $item['id'],
                'batchId' => $batch->id,
                'requestedQuantity' => 6,
                'unit' => 'box',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    expect($transfer['routeLabel'] ?? null)->toBe('Central Medical Store (WH-CENTRAL-001) -> Ward Satellite Store (WH-WARD-001)');
    expect($transfer['sourceWarehouseName'] ?? null)->toBe('Central Medical Store (WH-CENTRAL-001)');
    expect($transfer['destinationWarehouseName'] ?? null)->toBe('Ward Satellite Store (WH-WARD-001)');
    expect($transfer['lines'][0]['itemName'] ?? null)->toBe('Amoxicillin 500mg');
    expect($transfer['lines'][0]['batchNumber'] ?? null)->toBe('AMOX-2026-001');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'pending_approval',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'approved',
        ])
        ->assertOk();

    $reservation = DB::table('inventory_stock_reservations')
        ->where('source_type', 'inventory_warehouse_transfer')
        ->where('source_id', $transfer['id'])
        ->where('source_line_id', $transfer['lines'][0]['id'])
        ->first();

    expect($reservation)->not->toBeNull();
    expect($reservation->status)->toBe('active');
    expect((float) $reservation->quantity)->toBe(6.0);

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/inventory-procurement/warehouse-transfers?status=approved')
        ->assertOk()
        ->assertJsonPath('data.0.reservationSummary.state', 'held')
        ->assertJsonPath('data.0.reservationSummary.activeQuantity', 6)
        ->assertJsonPath('data.0.lines.0.reservedQuantity', 6)
        ->assertJsonPath('data.0.routeLabel', 'Central Medical Store (WH-CENTRAL-001) -> Ward Satellite Store (WH-WARD-001)');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/inventory-procurement/batches?itemId='.$item['id'])
        ->assertOk()
        ->assertJsonPath('data.0.batchNumber', 'AMOX-2026-001')
        ->assertJsonPath('data.0.reservedQuantity', 6)
        ->assertJsonPath('data.0.availableQuantity', 14);

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'in_transit',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_transit');

    expect((float) DB::table('inventory_batches')->where('id', $batch->id)->value('quantity'))->toBe(14.0);
    expect((float) DB::table('inventory_items')->where('id', $item['id'])->value('current_stock'))->toBe(14.0);
    expect(DB::table('inventory_stock_reservations')->where('id', $reservation->id)->value('status'))->toBe('consumed');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'])
        ->assertOk()
        ->assertJsonPath('data.reservationSummary.state', 'consumed')
        ->assertJsonPath('data.pickingSummary.dispatchedQuantity', 6)
        ->assertJsonPath('data.pickingSummary.remainingToReceive', 6)
        ->assertJsonPath('data.lines.0.dispatchRemainingQuantity', 0)
        ->assertJsonPath('data.lines.0.receiptRemainingQuantity', 6);

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'received',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'received');

    $destinationBatch = DB::table('inventory_batches')
        ->where('item_id', $item['id'])
        ->where('warehouse_id', $destinationWarehouse['id'])
        ->where('batch_number', 'AMOX-2026-001')
        ->first();

    expect($destinationBatch)->not->toBeNull();
    expect((float) $destinationBatch->quantity)->toBe(6.0);
    expect((float) DB::table('inventory_batches')->where('id', $batch->id)->value('quantity'))->toBe(14.0);
    expect((float) DB::table('inventory_items')->where('id', $item['id'])->value('current_stock'))->toBe(20.0);

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/inventory-procurement/stock-movements?sourceKey=warehouse_transfer')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.sourceKey', 'warehouse_transfer');
});

it('records short receipt discrepancies and only posts the accepted quantity to destination stock', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $scope = seedInventoryProcurementPlatformScope(
        user: $user,
        tenantCode: 'TZ-INV-RCV-SHORT',
        tenantName: 'Inventory Receipt Shortage Tenant',
        facilityCode: 'DAR-INV-RCV-SHORT',
        facilityName: 'Inventory Receipt Shortage Facility',
    );
    $headers = inventoryProcurementScopeHeaders($scope);

    $sourceWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-RCV-SHORT-SRC',
            'warehouseName' => 'Short Receipt Source Store',
        ])
        ->assertCreated()
        ->json('data');

    $destinationWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-RCV-SHORT-DST',
            'warehouseName' => 'Short Receipt Destination Store',
        ])
        ->assertCreated()
        ->json('data');

    $catalogItem = createApprovedMedicineCatalogItem([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'code' => 'MED-AMOX-RCV-SHORT-001',
        'name' => 'Amoxicillin 500mg',
        'category' => 'antibiotics',
        'unit' => 'capsule',
    ]);

    $item = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'clinicalCatalogItemId' => $catalogItem->id,
            'itemCode' => 'RX-RCV-SHORT-001',
            'itemName' => 'Amoxicillin 500mg',
            'category' => 'pharmaceutical',
            'unit' => 'box',
            'dispensingUnit' => 'capsule',
            'defaultWarehouseId' => $sourceWarehouse['id'],
            'storageConditions' => 'room_temperature_controlled',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $sourceWarehouse['id'],
            'quantity' => 20,
            'batchNumber' => 'AMOX-RCV-SHORT-001',
            'lotNumber' => 'LOT-AMOX-RCV-SHORT-001',
            'manufactureDate' => '2026-02-01',
            'expiryDate' => '2028-02-01',
            'reason' => 'Opening stock',
        ])
        ->assertCreated();

    $batch = DB::table('inventory_batches')
        ->where('item_id', $item['id'])
        ->where('batch_number', 'AMOX-RCV-SHORT-001')
        ->first();

    $transfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Short receipt variance',
            'lines' => [[
                'itemId' => $item['id'],
                'batchId' => $batch->id,
                'requestedQuantity' => 6,
                'unit' => 'box',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    foreach (['pending_approval', 'approved', 'in_transit'] as $status) {
        $this->actingAs($user)
            ->withHeaders($headers)
            ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
                'status' => $status,
            ])
            ->assertOk();
    }

    $receipt = $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'received',
            'receivedQuantities' => [
                $transfer['lines'][0]['id'] => 4,
            ],
            'receiptVarianceTypes' => [
                $transfer['lines'][0]['id'] => 'short',
            ],
            'receiptVarianceQuantities' => [
                $transfer['lines'][0]['id'] => 2,
            ],
            'receiptVarianceReasons' => [
                $transfer['lines'][0]['id'] => 'Two boxes were missing on handover.',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.receiptVarianceSummary.state', 'variance')
        ->assertJsonPath('data.receiptVarianceSummary.lineCount', 1)
        ->assertJsonPath('data.receiptVarianceSummary.types.short', 1)
        ->assertJsonPath('data.varianceReview.state', 'pending')
        ->assertJsonPath('data.varianceReview.needsReview', true)
        ->assertJsonPath('data.pickingSummary.remainingToReceive', 0)
        ->assertJsonPath('data.lines.0.receiptVarianceType', 'short')
        ->assertJsonPath('data.lines.0.receiptVarianceQuantity', 2)
        ->assertJsonPath('data.lines.0.reportedReceivedQuantity', 4)
        ->json('data');

    expect(collect($receipt['attentionSignals'] ?? [])->pluck('key')->all())->toContain('variance_review_pending');

    $destinationBatch = DB::table('inventory_batches')
        ->where('item_id', $item['id'])
        ->where('warehouse_id', $destinationWarehouse['id'])
        ->where('batch_number', 'AMOX-RCV-SHORT-001')
        ->first();

    expect($destinationBatch)->not->toBeNull();
    expect((float) $destinationBatch->quantity)->toBe(4.0);
    expect((float) DB::table('inventory_items')->where('id', $item['id'])->value('current_stock'))->toBe(18.0);
    expect((float) DB::table('inventory_warehouse_transfer_lines')->where('id', $transfer['lines'][0]['id'])->value('received_quantity'))->toBe(4.0);
    expect((float) DB::table('inventory_warehouse_transfer_lines')->where('id', $transfer['lines'][0]['id'])->value('reported_received_quantity'))->toBe(4.0);
    expect(DB::table('inventory_warehouse_transfer_lines')->where('id', $transfer['lines'][0]['id'])->value('receipt_variance_type'))->toBe('short');
    expect((float) DB::table('inventory_warehouse_transfer_lines')->where('id', $transfer['lines'][0]['id'])->value('receipt_variance_quantity'))->toBe(2.0);
});

it('records wrong batch receipt discrepancies without posting rejected stock into destination inventory', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $scope = seedInventoryProcurementPlatformScope(
        user: $user,
        tenantCode: 'TZ-INV-RCV-WB',
        tenantName: 'Inventory Receipt Wrong Batch Tenant',
        facilityCode: 'DAR-INV-RCV-WB',
        facilityName: 'Inventory Receipt Wrong Batch Facility',
    );
    $headers = inventoryProcurementScopeHeaders($scope);

    $sourceWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-RCV-WB-SRC',
            'warehouseName' => 'Wrong Batch Source Store',
        ])
        ->assertCreated()
        ->json('data');

    $destinationWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-RCV-WB-DST',
            'warehouseName' => 'Wrong Batch Destination Store',
        ])
        ->assertCreated()
        ->json('data');

    $catalogItem = createApprovedMedicineCatalogItem([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'code' => 'MED-AMOX-RCV-WB-001',
        'name' => 'Amoxicillin 500mg',
        'category' => 'antibiotics',
        'unit' => 'capsule',
    ]);

    $item = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'clinicalCatalogItemId' => $catalogItem->id,
            'itemCode' => 'RX-RCV-WB-001',
            'itemName' => 'Amoxicillin 500mg',
            'category' => 'pharmaceutical',
            'unit' => 'box',
            'dispensingUnit' => 'capsule',
            'defaultWarehouseId' => $sourceWarehouse['id'],
            'storageConditions' => 'room_temperature_controlled',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $sourceWarehouse['id'],
            'quantity' => 20,
            'batchNumber' => 'AMOX-RCV-WB-001',
            'lotNumber' => 'LOT-AMOX-RCV-WB-001',
            'manufactureDate' => '2026-02-01',
            'expiryDate' => '2028-02-01',
            'reason' => 'Opening stock',
        ])
        ->assertCreated();

    $batch = DB::table('inventory_batches')
        ->where('item_id', $item['id'])
        ->where('batch_number', 'AMOX-RCV-WB-001')
        ->first();

    $transfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Wrong batch variance',
            'lines' => [[
                'itemId' => $item['id'],
                'batchId' => $batch->id,
                'requestedQuantity' => 6,
                'unit' => 'box',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    foreach (['pending_approval', 'approved', 'in_transit'] as $status) {
        $this->actingAs($user)
            ->withHeaders($headers)
            ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
                'status' => $status,
            ])
            ->assertOk();
    }

    $receipt = $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'received',
            'receivedQuantities' => [
                $transfer['lines'][0]['id'] => 0,
            ],
            'receiptVarianceTypes' => [
                $transfer['lines'][0]['id'] => 'wrong_batch',
            ],
            'receiptVarianceQuantities' => [
                $transfer['lines'][0]['id'] => 6,
            ],
            'receiptVarianceReasons' => [
                $transfer['lines'][0]['id'] => 'Carrier delivered a different batch number than the dispatch note.',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.receiptVarianceSummary.state', 'variance')
        ->assertJsonPath('data.receiptVarianceSummary.types.wrong_batch', 1)
        ->assertJsonPath('data.varianceReview.state', 'pending')
        ->assertJsonPath('data.pickingSummary.remainingToReceive', 0)
        ->assertJsonPath('data.lines.0.receiptVarianceType', 'wrong_batch')
        ->assertJsonPath('data.lines.0.receiptVarianceQuantity', 6)
        ->assertJsonPath('data.lines.0.reportedReceivedQuantity', 6)
        ->json('data');

    expect(collect($receipt['attentionSignals'] ?? [])->pluck('key')->all())->toContain('variance_review_pending');

    $destinationBatch = DB::table('inventory_batches')
        ->where('item_id', $item['id'])
        ->where('warehouse_id', $destinationWarehouse['id'])
        ->where('batch_number', 'AMOX-RCV-WB-001')
        ->first();

    expect($destinationBatch)->toBeNull();
    expect((float) DB::table('inventory_items')->where('id', $item['id'])->value('current_stock'))->toBe(14.0);
    expect((float) DB::table('inventory_warehouse_transfer_lines')->where('id', $transfer['lines'][0]['id'])->value('received_quantity'))->toBe(0.0);
    expect((float) DB::table('inventory_warehouse_transfer_lines')->where('id', $transfer['lines'][0]['id'])->value('reported_received_quantity'))->toBe(6.0);
    expect(DB::table('inventory_warehouse_transfer_lines')->where('id', $transfer['lines'][0]['id'])->value('receipt_variance_type'))->toBe('wrong_batch');
    expect((float) DB::table('inventory_warehouse_transfer_lines')->where('id', $transfer['lines'][0]['id'])->value('receipt_variance_quantity'))->toBe(6.0);
});

it('moves received transfer variance into a review queue and marks it reviewed', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $scope = seedInventoryProcurementPlatformScope(
        user: $user,
        tenantCode: 'TZ-INV-RCV-REVIEW',
        tenantName: 'Inventory Receipt Review Tenant',
        facilityCode: 'DAR-INV-RCV-REVIEW',
        facilityName: 'Inventory Receipt Review Facility',
    );
    $headers = inventoryProcurementScopeHeaders($scope);

    $sourceWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-RCV-REV-SRC',
            'warehouseName' => 'Receipt Review Source Store',
        ])
        ->assertCreated()
        ->json('data');

    $destinationWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-RCV-REV-DST',
            'warehouseName' => 'Receipt Review Destination Store',
        ])
        ->assertCreated()
        ->json('data');

    $item = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'CONS-RCV-REV-001',
            'itemName' => 'Sterile Dressing Pack',
            'category' => 'medical_consumable',
            'unit' => 'pack',
            'defaultWarehouseId' => $sourceWarehouse['id'],
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $sourceWarehouse['id'],
            'quantity' => 15,
            'reason' => 'Opening stock',
        ])
        ->assertCreated();

    $transfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Variance review queue',
            'lines' => [[
                'itemId' => $item['id'],
                'requestedQuantity' => 5,
                'unit' => 'pack',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    foreach (['pending_approval', 'approved', 'in_transit'] as $status) {
        $this->actingAs($user)
            ->withHeaders($headers)
            ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
                'status' => $status,
            ])
            ->assertOk();
    }

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'received',
            'receivedQuantities' => [
                $transfer['lines'][0]['id'] => 4,
            ],
            'receiptVarianceTypes' => [
                $transfer['lines'][0]['id'] => 'short',
            ],
            'receiptVarianceQuantities' => [
                $transfer['lines'][0]['id'] => 1,
            ],
            'receiptVarianceReasons' => [
                $transfer['lines'][0]['id'] => 'One pack was missing on receipt.',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.varianceReview.state', 'pending');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/inventory-procurement/warehouse-transfers?varianceReview=pending')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $transfer['id'])
        ->assertJsonPath('data.0.varianceReview.state', 'pending');

    $reviewed = $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/receipt-variance-review', [
            'reviewStatus' => 'reviewed',
            'reviewNotes' => 'Shortage confirmed with source store and logged for follow-up.',
        ])
        ->assertOk()
        ->assertJsonPath('data.varianceReview.state', 'reviewed')
        ->assertJsonPath('data.varianceReview.notes', 'Shortage confirmed with source store and logged for follow-up.')
        ->json('data');

    expect($reviewed['varianceReview']['reviewedAt'] ?? null)->not->toBeNull();
    expect(collect($reviewed['attentionSignals'] ?? [])->pluck('key')->all())->not->toContain('variance_review_pending');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/inventory-procurement/warehouse-transfers?varianceReview=reviewed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $transfer['id'])
        ->assertJsonPath('data.0.varianceReview.state', 'reviewed');
});

it('blocks competing tracked transfer approvals until the active reservation is released', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $scope = seedInventoryProcurementPlatformScope(
        user: $user,
        tenantCode: 'TZ-INV-RES',
        tenantName: 'Inventory Reservation Tenant',
        facilityCode: 'DAR-INV-RES',
        facilityName: 'Inventory Reservation Facility',
    );
    $headers = inventoryProcurementScopeHeaders($scope);

    $sourceWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-RES-SRC',
            'warehouseName' => 'Reservation Source Store',
        ])
        ->assertCreated()
        ->json('data');

    $destinationWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-RES-DST',
            'warehouseName' => 'Reservation Destination Store',
        ])
        ->assertCreated()
        ->json('data');

    $catalogItem = createApprovedMedicineCatalogItem([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'code' => 'MED-CIPRO-RES-001',
        'name' => 'Ciprofloxacin 500mg',
        'category' => 'antibiotics',
        'unit' => 'tablet',
    ]);

    $item = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'clinicalCatalogItemId' => $catalogItem->id,
            'itemCode' => 'RX-RES-001',
            'itemName' => 'Ciprofloxacin 500mg',
            'category' => 'pharmaceutical',
            'unit' => 'box',
            'dispensingUnit' => 'tablet',
            'defaultWarehouseId' => $sourceWarehouse['id'],
            'storageConditions' => 'room_temperature_controlled',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $sourceWarehouse['id'],
            'quantity' => 20,
            'batchNumber' => 'CIPRO-2026-001',
            'lotNumber' => 'LOT-CIPRO-001',
            'manufactureDate' => '2026-02-01',
            'expiryDate' => '2028-02-01',
            'reason' => 'Opening stock',
        ])
        ->assertCreated();

    $batch = DB::table('inventory_batches')
        ->where('item_id', $item['id'])
        ->where('batch_number', 'CIPRO-2026-001')
        ->first();

    $firstTransfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'First reservation hold',
            'lines' => [[
                'itemId' => $item['id'],
                'batchId' => $batch->id,
                'requestedQuantity' => 12,
                'unit' => 'box',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    $secondTransfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Second reservation hold',
            'lines' => [[
                'itemId' => $item['id'],
                'batchId' => $batch->id,
                'requestedQuantity' => 10,
                'unit' => 'box',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    foreach ([$firstTransfer['id'], $secondTransfer['id']] as $transferId) {
        $this->actingAs($user)
            ->withHeaders($headers)
            ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transferId.'/status', [
                'status' => 'pending_approval',
            ])
            ->assertOk();
    }

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$firstTransfer['id'].'/status', [
            'status' => 'approved',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$secondTransfer['id'].'/status', [
            'status' => 'approved',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.requestedQuantity']);

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$firstTransfer['id'].'/status', [
            'status' => 'cancelled',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect(DB::table('inventory_stock_reservations')
        ->where('source_type', 'inventory_warehouse_transfer')
        ->where('source_id', $firstTransfer['id'])
        ->value('status'))->toBe('released');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$secondTransfer['id'].'/status', [
            'status' => 'approved',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
});

it('holds untracked warehouse transfer stock before dispatch and consumes the reservation on shipment', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $scope = seedInventoryProcurementPlatformScope(
        user: $user,
        tenantCode: 'TZ-INV-UNTRACK',
        tenantName: 'Inventory Untracked Reservation Tenant',
        facilityCode: 'DAR-INV-UNTRACK',
        facilityName: 'Inventory Untracked Reservation Facility',
    );
    $headers = inventoryProcurementScopeHeaders($scope);

    $sourceWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-UNT-SRC',
            'warehouseName' => 'Consumables Main Store',
        ])
        ->assertCreated()
        ->json('data');

    $destinationWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-UNT-DST',
            'warehouseName' => 'Consumables Ward Store',
        ])
        ->assertCreated()
        ->json('data');

    $item = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'CONS-RES-001',
            'itemName' => 'Examination Gloves',
            'category' => 'medical_consumable',
            'unit' => 'box',
            'defaultWarehouseId' => $sourceWarehouse['id'],
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $sourceWarehouse['id'],
            'quantity' => 10,
            'reason' => 'Opening stock',
        ])
        ->assertCreated();

    $firstTransfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Glove replenishment',
            'lines' => [[
                'itemId' => $item['id'],
                'requestedQuantity' => 7,
                'unit' => 'box',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    $secondTransfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Extra glove replenishment',
            'lines' => [[
                'itemId' => $item['id'],
                'requestedQuantity' => 5,
                'unit' => 'box',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    foreach ([$firstTransfer['id'], $secondTransfer['id']] as $transferId) {
        $this->actingAs($user)
            ->withHeaders($headers)
            ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transferId.'/status', [
                'status' => 'pending_approval',
            ])
            ->assertOk();
    }

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$firstTransfer['id'].'/status', [
            'status' => 'approved',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $reservation = DB::table('inventory_stock_reservations')
        ->where('source_type', 'inventory_warehouse_transfer')
        ->where('source_id', $firstTransfer['id'])
        ->first();

    expect($reservation)->not->toBeNull();
    expect($reservation->batch_id)->toBeNull();
    expect((float) $reservation->quantity)->toBe(7.0);

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$secondTransfer['id'].'/status', [
            'status' => 'approved',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.requestedQuantity']);

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$firstTransfer['id'].'/status', [
            'status' => 'in_transit',
            'dispatchedQuantities' => [
                $firstTransfer['lines'][0]['id'] => 5,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_transit');

    expect((float) DB::table('inventory_items')->where('id', $item['id'])->value('current_stock'))->toBe(5.0);
    expect(DB::table('inventory_stock_reservations')->where('id', $reservation->id)->value('status'))->toBe('consumed');

    $consumedMetadata = DB::table('inventory_stock_reservations')->where('id', $reservation->id)->value('metadata');
    $consumedMetadata = is_string($consumedMetadata) ? json_decode($consumedMetadata, true, 512, JSON_THROW_ON_ERROR) : $consumedMetadata;

    expect($consumedMetadata['consumedQuantity'] ?? null)->toBe(5);
    expect($consumedMetadata['releasedQuantity'] ?? null)->toBe(2);
});

it('requires stale warehouse transfer holds to be revalidated before dispatch', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $scope = seedInventoryProcurementPlatformScope(
        user: $user,
        tenantCode: 'TZ-INV-STALE',
        tenantName: 'Inventory Stale Reservation Tenant',
        facilityCode: 'DAR-INV-STALE',
        facilityName: 'Inventory Stale Reservation Facility',
    );
    $headers = inventoryProcurementScopeHeaders($scope);

    $sourceWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-STALE-SRC',
            'warehouseName' => 'Stale Hold Source Store',
        ])
        ->assertCreated()
        ->json('data');

    $destinationWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-STALE-DST',
            'warehouseName' => 'Stale Hold Destination Store',
        ])
        ->assertCreated()
        ->json('data');

    $item = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'CONS-STALE-001',
            'itemName' => 'Surgical Gauze',
            'category' => 'medical_consumable',
            'unit' => 'pack',
            'defaultWarehouseId' => $sourceWarehouse['id'],
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $sourceWarehouse['id'],
            'quantity' => 10,
            'reason' => 'Opening stock',
        ])
        ->assertCreated();

    $transfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Emergency ward replenishment',
            'lines' => [[
                'itemId' => $item['id'],
                'requestedQuantity' => 6,
                'unit' => 'pack',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    foreach (['pending_approval', 'approved'] as $status) {
        $this->actingAs($user)
            ->withHeaders($headers)
            ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
                'status' => $status,
            ])
            ->assertOk();
    }

    $reservation = DB::table('inventory_stock_reservations')
        ->where('source_type', 'inventory_warehouse_transfer')
        ->where('source_id', $transfer['id'])
        ->where('source_line_id', $transfer['lines'][0]['id'])
        ->first();

    expect($reservation)->not->toBeNull();

    DB::table('inventory_stock_reservations')
        ->where('id', $reservation->id)
        ->update([
            'expires_at' => now()->subMinutes(10),
            'updated_at' => now(),
        ]);

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'])
        ->assertOk()
        ->assertJsonPath('data.reservationSummary.state', 'stale')
        ->assertJsonPath('data.reservationSummary.dispatchRequiresRevalidation', true)
        ->assertJsonPath('data.reservationSummary.activeQuantity', 0)
        ->assertJsonPath('data.reservationSummary.staleQuantity', 6)
        ->assertJsonPath('data.lines.0.reservationState', 'stale')
        ->assertJsonPath('data.lines.0.reservedQuantity', 0)
        ->assertJsonPath('data.lines.0.staleReservedQuantity', 6)
        ->assertJsonPath('data.lines.0.isStaleReservation', true);

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'in_transit',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['revalidateReservation']);

    expect((float) DB::table('inventory_items')->where('id', $item['id'])->value('current_stock'))->toBe(10.0);

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'in_transit',
            'revalidateReservation' => true,
            'dispatchedQuantities' => [
                $transfer['lines'][0]['id'] => 6,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_transit');

    expect((float) DB::table('inventory_items')->where('id', $item['id'])->value('current_stock'))->toBe(4.0);

    $reservations = DB::table('inventory_stock_reservations')
        ->where('source_type', 'inventory_warehouse_transfer')
        ->where('source_id', $transfer['id'])
        ->orderBy('created_at')
        ->get(['status', 'quantity'])
        ->map(static fn ($row): array => [
            'status' => (string) $row->status,
            'quantity' => (float) $row->quantity,
        ])
        ->all();

    expect($reservations)->toEqual([
        ['status' => 'released', 'quantity' => 6.0],
        ['status' => 'consumed', 'quantity' => 6.0],
    ]);
});

it('automatically releases expired approved transfer holds and still requires a refreshed hold before dispatch', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $scope = seedInventoryProcurementPlatformScope(
        user: $user,
        tenantCode: 'TZ-INV-AUTOEXP',
        tenantName: 'Inventory Auto Expiry Tenant',
        facilityCode: 'DAR-INV-AUTOEXP',
        facilityName: 'Inventory Auto Expiry Facility',
    );
    $headers = inventoryProcurementScopeHeaders($scope);

    $sourceWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-AUTO-SRC',
            'warehouseName' => 'Auto Expiry Source Store',
        ])
        ->assertCreated()
        ->json('data');

    $destinationWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-AUTO-DST',
            'warehouseName' => 'Auto Expiry Destination Store',
        ])
        ->assertCreated()
        ->json('data');

    $item = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'CONS-AUTO-001',
            'itemName' => 'Sterile Dressing Pack',
            'category' => 'medical_consumable',
            'unit' => 'pack',
            'defaultWarehouseId' => $sourceWarehouse['id'],
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $sourceWarehouse['id'],
            'quantity' => 12,
            'reason' => 'Opening stock',
        ])
        ->assertCreated();

    $transfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Ward replenishment',
            'lines' => [[
                'itemId' => $item['id'],
                'requestedQuantity' => 6,
                'unit' => 'pack',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    foreach (['pending_approval', 'approved'] as $status) {
        $this->actingAs($user)
            ->withHeaders($headers)
            ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
                'status' => $status,
            ])
            ->assertOk();
    }

    DB::table('inventory_warehouse_transfers')
        ->where('id', $transfer['id'])
        ->update([
            'approved_at' => now()->subHours(9),
            'updated_at' => now()->subHours(9),
        ]);

    $reservation = DB::table('inventory_stock_reservations')
        ->where('source_type', 'inventory_warehouse_transfer')
        ->where('source_id', $transfer['id'])
        ->first();

    expect($reservation)->not->toBeNull();

    DB::table('inventory_stock_reservations')
        ->where('id', $reservation->id)
        ->update([
            'expires_at' => now()->subMinutes(15),
            'updated_at' => now(),
        ]);

    $this->artisan('inventory:expire-warehouse-transfer-reservations')
        ->assertSuccessful();

    expect(DB::table('inventory_stock_reservations')->where('id', $reservation->id)->value('status'))->toBe('released');

    $releasedMetadata = DB::table('inventory_stock_reservations')->where('id', $reservation->id)->value('metadata');
    $releasedMetadata = is_string($releasedMetadata) ? json_decode($releasedMetadata, true, 512, JSON_THROW_ON_ERROR) : $releasedMetadata;

    expect($releasedMetadata['releaseSource'] ?? null)->toBe('expired_reservation');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'])
        ->assertOk()
        ->assertJsonPath('data.reservationSummary.state', 'refresh_required')
        ->assertJsonPath('data.reservationSummary.dispatchRequiresRevalidation', true)
        ->assertJsonPath('data.attentionSignals.0.key', 'hold_refresh_required')
        ->assertJsonPath('data.attentionSignals.1.key', 'pick_overdue');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'in_transit',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['revalidateReservation']);
});

it('surfaces approval-stale and receive-overdue transfer alerts in the transfer list', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $scope = seedInventoryProcurementPlatformScope(
        user: $user,
        tenantCode: 'TZ-INV-ALERTS',
        tenantName: 'Inventory Alert Signals Tenant',
        facilityCode: 'DAR-INV-ALERTS',
        facilityName: 'Inventory Alert Signals Facility',
    );
    $headers = inventoryProcurementScopeHeaders($scope);

    $sourceWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-ALERT-SRC',
            'warehouseName' => 'Alert Source Store',
        ])
        ->assertCreated()
        ->json('data');

    $destinationWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-ALERT-DST',
            'warehouseName' => 'Alert Destination Store',
        ])
        ->assertCreated()
        ->json('data');

    $item = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'CONS-ALERT-001',
            'itemName' => 'Procedure Masks',
            'category' => 'medical_consumable',
            'unit' => 'box',
            'defaultWarehouseId' => $sourceWarehouse['id'],
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $sourceWarehouse['id'],
            'quantity' => 20,
            'reason' => 'Opening stock',
        ])
        ->assertCreated();

    $approvalTransfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Pending approval alert',
            'lines' => [[
                'itemId' => $item['id'],
                'requestedQuantity' => 3,
                'unit' => 'box',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$approvalTransfer['id'].'/status', [
            'status' => 'pending_approval',
        ])
        ->assertOk();

    DB::table('inventory_warehouse_transfers')
        ->where('id', $approvalTransfer['id'])
        ->update([
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
        ]);

    $receiveTransfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Receive overdue alert',
            'lines' => [[
                'itemId' => $item['id'],
                'requestedQuantity' => 4,
                'unit' => 'box',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    foreach (['pending_approval', 'approved'] as $status) {
        $this->actingAs($user)
            ->withHeaders($headers)
            ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$receiveTransfer['id'].'/status', [
                'status' => $status,
            ])
            ->assertOk();
    }

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$receiveTransfer['id'].'/status', [
            'status' => 'in_transit',
            'dispatchedQuantities' => [
                $receiveTransfer['lines'][0]['id'] => 4,
            ],
        ])
        ->assertOk();

    DB::table('inventory_warehouse_transfers')
        ->where('id', $receiveTransfer['id'])
        ->update([
            'dispatched_at' => now()->subHours(13),
            'updated_at' => now()->subHours(13),
        ]);

    $payload = $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/inventory-procurement/warehouse-transfers')
        ->assertOk()
        ->json('data');

    $approvalTransferRow = collect($payload)->firstWhere('id', $approvalTransfer['id']);
    $receiveTransferRow = collect($payload)->firstWhere('id', $receiveTransfer['id']);

    expect($approvalTransferRow)->not->toBeNull();
    expect($receiveTransferRow)->not->toBeNull();
    expect(collect($approvalTransferRow['attentionSignals'] ?? [])->pluck('key')->all())->toContain('approval_stale');
    expect(collect($receiveTransferRow['attentionSignals'] ?? [])->pluck('key')->all())->toContain('receive_overdue');
});

it('records packed quantities and uses them as the default dispatch quantity for warehouse transfers', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $scope = seedInventoryProcurementPlatformScope(
        user: $user,
        tenantCode: 'TZ-INV-PACK',
        tenantName: 'Inventory Packed Flow Tenant',
        facilityCode: 'DAR-INV-PACK',
        facilityName: 'Inventory Packed Flow Facility',
    );
    $headers = inventoryProcurementScopeHeaders($scope);

    $sourceWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-PACK-SRC',
            'warehouseName' => 'Packed Flow Source Store',
        ])
        ->assertCreated()
        ->json('data');
    $destinationWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-PACK-DST',
            'warehouseName' => 'Packed Flow Destination Store',
        ])
        ->assertCreated()
        ->json('data');

    $item = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'CONS-PACK-001',
            'itemName' => 'Sterile Procedure Packs',
            'category' => 'medical_consumable',
            'unit' => 'pack',
            'defaultWarehouseId' => $sourceWarehouse['id'],
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $sourceWarehouse['id'],
            'quantity' => 12,
            'reason' => 'Opening stock',
        ])
        ->assertCreated();

    $transfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Packed dispatch test',
            'lines' => [[
                'itemId' => $item['id'],
                'requestedQuantity' => 8,
                'unit' => 'pack',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    foreach (['pending_approval', 'approved'] as $status) {
        $this->actingAs($user)
            ->withHeaders($headers)
            ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
                'status' => $status,
            ])
            ->assertOk();
    }

    $packed = $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'packed',
            'packNotes' => 'Packed into tamper-evident tote for dispatch.',
            'packedQuantities' => [
                $transfer['lines'][0]['id'] => 6,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'packed')
        ->assertJsonPath('data.dispatchNoteNumber', 'DN-'.$transfer['transfer_number'])
        ->json('data');

    expect($packed['dispatchNoteNumber'] ?? null)->toBe('DN-'.$transfer['transfer_number']);
    expect(DB::table('inventory_warehouse_transfers')->where('id', $transfer['id'])->value('dispatch_note_number'))->toBe('DN-'.$transfer['transfer_number']);
    expect((float) DB::table('inventory_warehouse_transfer_lines')->where('id', $transfer['lines'][0]['id'])->value('packed_quantity'))->toBe(6.0);

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'in_transit',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_transit')
        ->assertJsonPath('data.lines.0.dispatched_quantity', '6.000');

    expect((float) DB::table('inventory_items')->where('id', $item['id'])->value('current_stock'))->toBe(6.0);
});

it('renders warehouse transfer pick slip and dispatch note documents', function (): void {
    $user = makeInventoryProcurementUser([
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.read',
    ]);

    $scope = seedInventoryProcurementPlatformScope(
        user: $user,
        tenantCode: 'TZ-INV-DOC',
        tenantName: 'Inventory Transfer Documents Tenant',
        facilityCode: 'DAR-INV-DOC',
        facilityName: 'Inventory Transfer Documents Facility',
    );
    $headers = inventoryProcurementScopeHeaders($scope);

    $sourceWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-DOC-SRC',
            'warehouseName' => 'Document Source Store',
        ])
        ->assertCreated()
        ->json('data');
    $destinationWarehouse = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouses', [
            'warehouseCode' => 'WH-DOC-DST',
            'warehouseName' => 'Document Destination Store',
        ])
        ->assertCreated()
        ->json('data');

    $item = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'CONS-DOC-001',
            'itemName' => 'Examination Gloves',
            'category' => 'medical_consumable',
            'unit' => 'box',
            'defaultWarehouseId' => $sourceWarehouse['id'],
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/stock-movements', [
            'itemId' => $item['id'],
            'movementType' => 'receive',
            'destinationWarehouseId' => $sourceWarehouse['id'],
            'quantity' => 15,
            'reason' => 'Opening stock',
        ])
        ->assertCreated();

    $transfer = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/inventory-procurement/warehouse-transfers', [
            'sourceWarehouseId' => $sourceWarehouse['id'],
            'destinationWarehouseId' => $destinationWarehouse['id'],
            'reason' => 'Document route test',
            'lines' => [[
                'itemId' => $item['id'],
                'requestedQuantity' => 5,
                'unit' => 'box',
            ]],
        ])
        ->assertCreated()
        ->json('data');

    foreach (['pending_approval', 'approved'] as $status) {
        $this->actingAs($user)
            ->withHeaders($headers)
            ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
                'status' => $status,
            ])
            ->assertOk();
    }

    $this->actingAs($user)
        ->withHeaders($headers)
        ->patchJson('/api/v1/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/status', [
            'status' => 'packed',
            'packedQuantities' => [
                $transfer['lines'][0]['id'] => 5,
            ],
        ])
        ->assertOk();

    $this->actingAs($user)
        ->get('/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/pick-slip')
        ->assertOk()
        ->assertSee($transfer['transfer_number']);

    $this->actingAs($user)
        ->get('/inventory-procurement/warehouse-transfers/'.$transfer['id'].'/dispatch-note')
        ->assertOk()
        ->assertSee('DN-'.$transfer['transfer_number']);
});
