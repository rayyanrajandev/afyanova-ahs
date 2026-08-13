<?php

use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseAuditLogModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function warehouseHardeningMakeUser(array $permissions = []): User
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
function warehouseHardeningCreateWarehouse(User $user, array $overrides = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/warehouses', array_merge([
            'warehouseCode' => 'WH-'.strtoupper(Str::random(8)),
            'warehouseName' => 'Main Medical Store',
            'warehouseType' => 'central',
            'location' => 'Building A',
            'contactPerson' => 'Store Supervisor',
            'phone' => '+255711000000',
            'email' => 'warehouse@example.test',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

it('writes warehouse status transition parity metadata in audit logs', function (): void {
    $user = warehouseHardeningMakeUser([
        'inventory.procurement.manage-warehouses',
        'inventory.procurement.view-audit-logs',
    ]);

    $warehouse = warehouseHardeningCreateWarehouse($user);

    $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/warehouses/'.$warehouse['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Warehouse maintenance period',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $statusAudit = InventoryWarehouseAuditLogModel::query()
        ->where('inventory_warehouse_id', $warehouse['id'])
        ->where('action', 'inventory-warehouse.status.updated')
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
        ->getJson('/api/v1/inventory-procurement/warehouses/'.$warehouse['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'inventory-warehouse.status.updated');

    $response = $this->actingAs($user)
        ->get('/api/v1/inventory-procurement/warehouses/'.$warehouse['id'].'/audit-logs/export');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
});

it('rejects warehouse detail update when status fields are provided', function (): void {
    $user = warehouseHardeningMakeUser(['inventory.procurement.manage-warehouses']);

    $warehouse = warehouseHardeningCreateWarehouse($user);

    $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/warehouses/'.$warehouse['id'], [
            'warehouseName' => 'Main Medical Store Updated',
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});
