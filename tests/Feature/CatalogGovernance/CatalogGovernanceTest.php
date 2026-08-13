<?php

use App\Models\User;
use App\Support\CatalogGovernance\InventoryClinicalLinkGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeCatalogGovernanceUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @return array<string, mixed>
 */
function catalogGovernanceClinicalItem(array $overrides = []): array
{
    $item = array_merge([
        'id' => (string) Str::uuid(),
        'tenant_id' => null,
        'facility_id' => null,
        'facility_tier' => null,
        'catalog_type' => 'lab_test',
        'code' => 'LAB-CBC-001',
        'name' => 'Complete Blood Count',
        'department_id' => null,
        'category' => 'hematology',
        'unit' => 'test',
        'description' => 'Clinical catalog item for governance tests.',
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'codes' => json_encode(['LOCAL' => 'LAB-CBC-001'], JSON_THROW_ON_ERROR),
        'status' => 'active',
        'status_reason' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    if (is_array($item['metadata'] ?? null)) {
        $item['metadata'] = json_encode($item['metadata'], JSON_THROW_ON_ERROR);
    }

    if (is_array($item['codes'] ?? null)) {
        $item['codes'] = json_encode($item['codes'], JSON_THROW_ON_ERROR);
    }

    DB::table('platform_clinical_catalog_items')->insert($item);

    return $item;
}

/**
 * @return array<string, mixed>
 */
function catalogGovernanceInventoryItem(array $overrides = []): array
{
    $item = array_merge([
        'id' => (string) Str::uuid(),
        'tenant_id' => null,
        'facility_id' => null,
        'item_code' => 'LAB-REAG-CBC-KIT',
        'item_name' => 'CBC reagent kit',
        'category' => 'laboratory',
        'subcategory' => 'hematology_reagent',
        'unit' => 'kit',
        'current_stock' => 10,
        'reorder_level' => 2,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('inventory_items')->insert($item);

    return $item;
}

it('rejects a CBC test saved as a laboratory reagent inventory item', function (): void {
    $user = makeCatalogGovernanceUser(['inventory.procurement.manage-items']);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'LAB-CBC-001',
            'itemName' => 'Complete Blood Count',
            'category' => 'laboratory',
            'subcategory' => 'hematology_reagent',
            'unit' => 'test',
            'storageConditions' => 'room_temperature',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['itemName']);
});

it('allows CBC reagent kits as physical laboratory inventory stock', function (): void {
    $user = makeCatalogGovernanceUser(['inventory.procurement.manage-items']);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'LAB-REAG-CBC-KIT',
            'itemName' => 'CBC reagent kit',
            'category' => 'laboratory',
            'subcategory' => 'hematology_reagent',
            'unit' => 'kit',
            'storageConditions' => 'refrigerated_2_8c',
        ])
        ->assertCreated()
        ->assertJsonPath('data.itemCode', 'LAB-REAG-CBC-KIT');
});

it('rejects a laboratory reagent inventory item with a clinical catalog link', function (): void {
    $user = makeCatalogGovernanceUser(['inventory.procurement.manage-items']);
    $labTest = catalogGovernanceClinicalItem();

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'LAB-REAG-CBC-KIT',
            'itemName' => 'CBC reagent kit',
            'category' => 'laboratory',
            'subcategory' => 'hematology_reagent',
            'unit' => 'kit',
            'storageConditions' => 'refrigerated_2_8c',
            'clinicalCatalogItemId' => $labTest['id'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['clinicalCatalogItemId']);
});

it('requires medicine inventory to link to a formulary catalog item', function (): void {
    $user = makeCatalogGovernanceUser(['inventory.procurement.manage-items']);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'MED-PARA-500TAB',
            'itemName' => 'Paracetamol 500mg Tablet',
            'category' => 'pharmaceutical',
            'subcategory' => 'analgesics',
            'unit' => 'box',
            'storageConditions' => 'room_temperature',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['clinicalCatalogItemId']);
});

it('uses linked clinical catalog identity for service price list entries', function (): void {
    $user = makeCatalogGovernanceUser(['billing.service-catalog.manage']);
    $labTest = catalogGovernanceClinicalItem([
        'code' => 'LAB-CBC-001',
        'name' => 'Complete Blood Count',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing-service-catalog/items', [
            'clinicalCatalogItemId' => $labTest['id'],
            'serviceCode' => 'WRONG-CODE',
            'serviceName' => 'Wrong Name',
            'serviceType' => 'laboratory',
            'unit' => 'test',
            'basePrice' => 12000,
            'currencyCode' => 'TZS',
        ])
        ->assertCreated()
        ->assertJsonPath('data.serviceCode', 'LAB-CBC-001')
        ->assertJsonPath('data.serviceName', 'Complete Blood Count');
});

it('warns but does not block missing NHIF and MSD standards codes', function (): void {
    $inventoryUser = makeCatalogGovernanceUser(['inventory.procurement.manage-items']);

    $this->actingAs($inventoryUser)
        ->postJson('/api/v1/inventory-procurement/items', [
            'itemCode' => 'CON-SYRINGE-10ML',
            'itemName' => 'Syringes 10ml',
            'category' => 'medical_consumable',
            'unit' => 'box',
        ])
        ->assertCreated()
        ->assertJson(fn ($json) => $json
            ->where('data.itemCode', 'CON-SYRINGE-10ML')
            ->has('data.standardsWarnings.0')
            ->etc()
        );

    $billingUser = makeCatalogGovernanceUser(['billing.service-catalog.manage']);

    $this->actingAs($billingUser)
        ->postJson('/api/v1/billing-service-catalog/items', [
            'serviceCode' => 'LAB-UNLINKED-001',
            'serviceName' => 'Unlinked lab tariff',
            'serviceType' => 'laboratory',
            'unit' => 'test',
            'basePrice' => 5000,
            'currencyCode' => 'TZS',
        ])
        ->assertCreated()
        ->assertJson(fn ($json) => $json
            ->where('data.serviceCode', 'LAB-UNLINKED-001')
            ->has('data.standardsWarnings.0')
            ->etc()
        );
});

it('validates offline-created records against backend inventory rules before sync acceptance', function (): void {
    $labTest = catalogGovernanceClinicalItem();

    $result = app(InventoryClinicalLinkGuard::class)->validateOfflineSyncPayload([
        'item_code' => 'LAB-REAG-CBC-KIT',
        'item_name' => 'CBC reagent kit',
        'category' => 'laboratory',
        'clinical_catalog_item_id' => $labTest['id'],
    ]);

    expect($result['errors'])->toHaveKey('clinicalCatalogItemId');
});

it('syncs a lab test consumption recipe to laboratory inventory stock without direct catalog inventory linking', function (): void {
    $user = makeCatalogGovernanceUser(['platform.clinical-catalog.manage-lab-tests']);
    $labTest = catalogGovernanceClinicalItem();
    $reagent = catalogGovernanceInventoryItem();

    $this->actingAs($user)
        ->putJson("/api/v1/platform/admin/clinical-catalogs/lab-tests/{$labTest['id']}/consumption-recipe", [
            'items' => [
                [
                    'inventoryItemId' => $reagent['id'],
                    'quantityPerOrder' => '0.2500',
                    'unit' => 'kit',
                    'wasteFactorPercent' => '5',
                    'consumptionStage' => 'processing',
                    'notes' => 'Automated analyzer reagent use per CBC order.',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.items.0.inventoryItemId', $reagent['id'])
        ->assertJsonPath('data.items.0.inventoryItem.itemCode', 'LAB-REAG-CBC-KIT');

    $this->assertDatabaseHas('clinical_catalog_consumption_recipe_items', [
        'clinical_catalog_item_id' => $labTest['id'],
        'inventory_item_id' => $reagent['id'],
        'consumption_stage' => 'processing',
    ]);

    $this->assertDatabaseMissing('inventory_items', [
        'id' => $reagent['id'],
        'clinical_catalog_item_id' => $labTest['id'],
    ]);

    $this->assertDatabaseHas('platform_clinical_catalog_item_audit_logs', [
        'platform_clinical_catalog_item_id' => $labTest['id'],
        'action' => 'consumption_recipe_synced',
    ]);
});

it('rejects pharmaceutical inventory in a lab test consumption recipe', function (): void {
    $user = makeCatalogGovernanceUser(['platform.clinical-catalog.manage-lab-tests']);
    $labTest = catalogGovernanceClinicalItem();
    $medicine = catalogGovernanceInventoryItem([
        'item_code' => 'MED-PARA-500TAB',
        'item_name' => 'Paracetamol 500mg Tablet',
        'category' => 'pharmaceutical',
        'subcategory' => 'analgesics',
        'unit' => 'tablet',
    ]);

    $this->actingAs($user)
        ->putJson("/api/v1/platform/admin/clinical-catalogs/lab-tests/{$labTest['id']}/consumption-recipe", [
            'items' => [
                [
                    'inventoryItemId' => $medicine['id'],
                    'quantityPerOrder' => '1',
                    'unit' => 'tablet',
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.inventoryItemId']);
});
