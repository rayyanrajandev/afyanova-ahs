<?php

use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventorySupplierAuditLogModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function supplierHardeningMakeUser(array $permissions = []): User
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
function supplierHardeningCreateSupplier(User $user, array $overrides = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/inventory-procurement/suppliers', array_merge([
            'supplierCode' => 'SUP-'.strtoupper(Str::random(8)),
            'supplierName' => 'MedSupply Ltd',
            'contactPerson' => 'Amina Supplier',
            'phone' => '+255700000000',
            'email' => 'supplier@example.test',
            'countryCode' => 'TZ',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

it('writes supplier status transition parity metadata in audit logs', function (): void {
    $user = supplierHardeningMakeUser([
        'inventory.procurement.manage-suppliers',
        'inventory.procurement.view-audit-logs',
    ]);

    $supplier = supplierHardeningCreateSupplier($user);

    $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/suppliers/'.$supplier['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Supplier contract under review',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $statusAudit = InventorySupplierAuditLogModel::query()
        ->where('inventory_supplier_id', $supplier['id'])
        ->where('action', 'inventory-supplier.status.updated')
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
        ->getJson('/api/v1/inventory-procurement/suppliers/'.$supplier['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'inventory-supplier.status.updated');

    $response = $this->actingAs($user)
        ->get('/api/v1/inventory-procurement/suppliers/'.$supplier['id'].'/audit-logs/export');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
});

it('rejects supplier detail update when status fields are provided', function (): void {
    $user = supplierHardeningMakeUser(['inventory.procurement.manage-suppliers']);

    $supplier = supplierHardeningCreateSupplier($user);

    $this->actingAs($user)
        ->patchJson('/api/v1/inventory-procurement/suppliers/'.$supplier['id'], [
            'supplierName' => 'MedSupply Updated',
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});
