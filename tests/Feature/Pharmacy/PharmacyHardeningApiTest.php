<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderAuditLogModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    pharmacyHardeningEnsureActiveApprovedMedicineCatalogItem();
});

function pharmacyHardeningGrantPermission(User $user, string $permission): void
{
    Permission::query()->firstOrCreate(['name' => $permission]);
    $user->givePermissionTo($permission);
}

function pharmacyHardeningEnsureActiveApprovedMedicineCatalogItem(array $overrides = []): ClinicalCatalogItemModel
{
    $attributes = array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'formulary_item',
        'code' => 'ATC:N02BE01',
        'name' => 'Paracetamol 500mg',
        'department_id' => null,
        'category' => 'analgesics',
        'unit' => 'tablet',
        'description' => 'Default approved medicine fixture',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides);

    $match = [
        'tenant_id' => $attributes['tenant_id'],
        'facility_id' => $attributes['facility_id'],
        'catalog_type' => $attributes['catalog_type'],
        'code' => $attributes['code'],
    ];

    unset($attributes['tenant_id'], $attributes['facility_id'], $attributes['catalog_type'], $attributes['code']);

    return ClinicalCatalogItemModel::query()->firstOrCreate($match, $attributes);
}

function pharmacyHardeningMakeUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach (array_merge([
        'pharmacy.orders.read',
        'pharmacy.orders.create',
        'pharmacy.orders.update-status',
        'pharmacy.orders.verify-dispense',
    ], $permissions) as $permission) {
        pharmacyHardeningGrantPermission($user, $permission);
    }

    return $user;
}

function pharmacyHardeningMakePatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Halima',
        'middle_name' => null,
        'last_name' => 'Mdee',
        'gender' => 'female',
        'date_of_birth' => '1994-02-11',
        'phone' => '+255700001122',
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

/**
 * @return array<string, mixed>
 */
function pharmacyHardeningCreateOrder(User $user, string $patientId, array $overrides = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge([
            'patientId' => $patientId,
            'orderedAt' => now()->toDateTimeString(),
            'medicationCode' => 'ATC:N02BE01',
            'medicationName' => 'Paracetamol 500mg',
            'dosageInstruction' => 'Take 1 tablet every 8 hours',
            'clinicalIndication' => 'Pain',
            'quantityPrescribed' => 12,
            'quantityDispensed' => 0,
            'dispensingNotes' => 'Initial dispensing note',
        ], $overrides))
        ->assertCreated()
        ->json('data');
}

it('writes pharmacy status transition parity metadata in audit logs', function (): void {
    $user = pharmacyHardeningMakeUser(['pharmacy-orders.view-audit-logs']);
    $patient = pharmacyHardeningMakePatient();
    $order = pharmacyHardeningCreateOrder($user, $patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'Medication recalled by supplier',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $statusAudit = PharmacyOrderAuditLogModel::query()
        ->where('pharmacy_order_id', $order['id'])
        ->where('action', 'pharmacy-order.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();

    $metadata = $statusAudit?->metadata ?? [];
    expect($metadata['transition'] ?? [])->toMatchArray([
        'from' => 'pending',
        'to' => 'cancelled',
    ]);
    expect($metadata)->toMatchArray([
        'reason_required' => true,
        'reason_provided' => true,
        'quantity_dispensed_input_provided' => false,
        'dispensing_notes_input_provided' => false,
        'dispensed_timestamp_required' => false,
        'dispensed_timestamp_provided' => false,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$order['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'pharmacy-order.status.updated');

    $response = $this->actingAs($user)
        ->get('/api/v1/pharmacy-orders/'.$order['id'].'/audit-logs/export');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
});

it('writes pharmacy verify and reconciliation parity metadata in audit logs', function (): void {
    $user = pharmacyHardeningMakeUser([
        'pharmacy.orders.reconcile',
    ]);
    $patient = pharmacyHardeningMakePatient();
    $catalogItem = pharmacyHardeningEnsureActiveApprovedMedicineCatalogItem();
    $inventoryItem = InventoryItemModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'clinical_catalog_item_id' => $catalogItem->id,
        'item_code' => 'ATC:N02BE01',
        'item_name' => 'Paracetamol 500mg',
        'category' => 'pharmaceutical',
        'unit' => 'tablet',
        'current_stock' => 100,
        'reorder_level' => 10,
        'max_stock_level' => 200,
        'status' => 'active',
    ]);

    InventoryItemUnitModel::query()->create([
        'tenant_id' => $inventoryItem->tenant_id,
        'facility_id' => $inventoryItem->facility_id,
        'item_id' => $inventoryItem->id,
        'unit_name' => $inventoryItem->unit,
        'unit_code' => null,
        'base_quantity' => 1,
        'is_base_unit' => true,
        'is_default_sales_unit' => true,
        'is_default_purchase_unit' => true,
        'is_active' => true,
    ]);

    $order = pharmacyHardeningCreateOrder($user, $patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Prepared for dispense verification.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_preparation');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'dispensed',
            'quantityDispensed' => 12,
            'dispensingNotes' => 'Dispensed full quantity',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'dispensed');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/verify', [
            'verificationNote' => 'Dispense verified by pharmacist.',
        ])
        ->assertOk();

    $verifyAudit = PharmacyOrderAuditLogModel::query()
        ->where('pharmacy_order_id', $order['id'])
        ->where('action', 'pharmacy-order.dispense.verified')
        ->latest('created_at')
        ->first();

    expect($verifyAudit)->not->toBeNull();
    $verifyMetadata = $verifyAudit?->metadata ?? [];
    expect($verifyMetadata)->toMatchArray([
        'workflow_status_required' => 'dispensed',
        'workflow_status_satisfied' => true,
        'dispensed_timestamp_required' => true,
        'dispensed_timestamp_provided' => true,
        'verification_note_required' => false,
        'verification_note_provided' => true,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/reconciliation', [
            'reconciliationStatus' => 'exception',
            'reconciliationNote' => 'Patient reported mild side effects.',
        ])
        ->assertOk()
        ->assertJsonPath('data.reconciliationStatus', 'exception');

    $reconcileAudit = PharmacyOrderAuditLogModel::query()
        ->where('pharmacy_order_id', $order['id'])
        ->where('action', 'pharmacy-order.reconciliation.updated')
        ->latest('created_at')
        ->first();

    expect($reconcileAudit)->not->toBeNull();
    $reconcileMetadata = $reconcileAudit?->metadata ?? [];
    expect($reconcileMetadata['transition'] ?? [])->toMatchArray([
        'from' => 'pending',
        'to' => 'exception',
    ]);
    expect($reconcileMetadata)->toMatchArray([
        'workflow_status_required' => 'dispensed',
        'workflow_status_satisfied' => true,
        'verification_required' => true,
        'verification_present' => true,
        'reconciliation_note_required' => true,
        'reconciliation_note_provided' => true,
        'reconciled_timestamp_required' => true,
        'reconciled_timestamp_provided' => true,
        'reconciled_by_required' => true,
        'reconciled_by_provided' => true,
    ]);
});

it('rejects pharmacy detail update when lifecycle fields are provided', function (): void {
    $user = pharmacyHardeningMakeUser();
    $patient = pharmacyHardeningMakePatient();
    $order = pharmacyHardeningCreateOrder($user, $patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'], [
            'dosageInstruction' => 'Take after meals only',
            'status' => 'cancelled',
            'formularyDecisionStatus' => 'restricted',
            'reconciliationStatus' => 'completed',
            'verificationNote' => 'not allowed here',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'status',
            'formularyDecisionStatus',
            'reconciliationStatus',
            'verificationNote',
        ]);
});
