<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderAuditLogModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    // The abilities the routes declare. `pharmacy.orders.create` and
    // `pharmacy.orders.update-status` are granted by no role and consulted by no
    // route — prescribing and dispensing are separate abilities.
    foreach (array_merge([
        'pharmacy.orders.read',
        'medication.prescribe',
        'medication.dispense',
        'pharmacy.orders.verify-dispense',
        'pharmacy.orders.audit-logs.view',
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
 * Stock the dispense path can actually issue against.
 *
 * @return array{0: InventoryItemModel, 1: ClinicalCatalogItemModel}
 */
function pharmacyHardeningEnsureDispensableStock(): array
{
    $catalogItem = pharmacyHardeningEnsureActiveApprovedMedicineCatalogItem();

    $inventoryItem = InventoryItemModel::query()->firstOrCreate([
        'item_code' => 'ATC:N02BE01',
    ], [
        'tenant_id' => null,
        'facility_id' => null,
        'clinical_catalog_item_id' => $catalogItem->id,
        'item_name' => 'Paracetamol 500mg',
        'category' => 'pharmaceutical',
        'unit' => 'tablet',
        'current_stock' => 100,
        'reorder_level' => 10,
        'max_stock_level' => 200,
        'status' => 'active',
    ]);

    InventoryItemUnitModel::query()->firstOrCreate([
        'item_id' => $inventoryItem->id,
        'unit_name' => $inventoryItem->unit,
    ], [
        'tenant_id' => $inventoryItem->tenant_id,
        'facility_id' => $inventoryItem->facility_id,
        'unit_code' => null,
        'base_quantity' => 1,
        'is_base_unit' => true,
        'is_default_sales_unit' => true,
        'is_default_purchase_unit' => true,
        'is_active' => true,
    ]);

    return [$inventoryItem, $catalogItem];
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
    // Verification now refuses the pharmacist who released the medicine, so
    // this fixture needs the second pair of eyes it always implied.
    $checker = pharmacyHardeningMakeUser([
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

    $this->actingAs($checker)
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

/**
 * The dispensing sequence is a safety control, not a convenience: medicine
 * cannot be handed over before someone has accepted the prescription and
 * prepared it, and cannot be signed off before it has been handed over.
 * PharmacyOrderStatus::allowedWorkflowTransitions is where that is decided,
 * and nothing had been pinning it.
 */
it('refuses to dispense a prescription that was never prepared', function (): void {
    $user = pharmacyHardeningMakeUser();
    $patient = pharmacyHardeningMakePatient();
    $order = pharmacyHardeningCreateOrder($user, $patient->id);

    expect($order['status'])->toBe('pending');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'dispensed',
            'quantityDispensed' => 12,
        ])
        ->assertUnprocessable();

    // The refusal has to leave the order where it was, not half-moved.
    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$order['id'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');
});

it('refuses to verify a dispense that has not happened', function (): void {
    $user = pharmacyHardeningMakeUser();
    $patient = pharmacyHardeningMakePatient();
    $order = pharmacyHardeningCreateOrder($user, $patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/verify', [
            'verificationNote' => 'Checked',
        ])
        ->assertUnprocessable();
});

it('refuses to reopen an order that has already been cancelled', function (): void {
    $user = pharmacyHardeningMakeUser();
    $patient = pharmacyHardeningMakePatient();
    $order = pharmacyHardeningCreateOrder($user, $patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'Prescriber withdrew the order',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'in_preparation',
        ])
        ->assertUnprocessable();
});

/**
 * Verification is the second pair of eyes, so it has to be a second pair.
 *
 * The table carried verified_by_user_id from the start and no counterpart, so
 * pharmacy wrote down who signed off with nothing to compare it against. The
 * laboratory and radiology release paths have refused self-verification since
 * they were built.
 */
it('records who released the medicine', function (): void {
    $user = pharmacyHardeningMakeUser();
    $patient = pharmacyHardeningMakePatient();
    pharmacyHardeningEnsureDispensableStock();
    $order = pharmacyHardeningCreateOrder($user, $patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'in_preparation',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'dispensed',
            'quantityDispensed' => 12,
        ])
        ->assertOk()
        ->assertJsonPath('data.dispensedByUserId', $user->id);
});

it('refuses a pharmacist verifying their own dispense', function (): void {
    $dispenser = pharmacyHardeningMakeUser();
    $patient = pharmacyHardeningMakePatient();
    pharmacyHardeningEnsureDispensableStock();
    $order = pharmacyHardeningCreateOrder($dispenser, $patient->id);

    $this->actingAs($dispenser)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'in_preparation',
        ])
        ->assertOk();

    $this->actingAs($dispenser)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'dispensed',
            'quantityDispensed' => 12,
        ])
        ->assertOk();

    $this->actingAs($dispenser)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/verify', [
            'verificationNote' => 'Checked my own work',
        ])
        ->assertUnprocessable();

    // And the refusal leaves no half-written sign-off behind.
    $this->actingAs($dispenser)
        ->getJson('/api/v1/pharmacy-orders/'.$order['id'])
        ->assertOk()
        ->assertJsonPath('data.verifiedAt', null)
        ->assertJsonPath('data.verifiedByUserId', null);
});

it('lets a second pharmacist verify the same dispense', function (): void {
    $dispenser = pharmacyHardeningMakeUser();
    $checker = pharmacyHardeningMakeUser();
    $patient = pharmacyHardeningMakePatient();
    pharmacyHardeningEnsureDispensableStock();
    $order = pharmacyHardeningCreateOrder($dispenser, $patient->id);

    $this->actingAs($dispenser)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'in_preparation',
        ])
        ->assertOk();

    $this->actingAs($dispenser)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'dispensed',
            'quantityDispensed' => 12,
        ])
        ->assertOk();

    $this->actingAs($checker)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/verify', [
            'verificationNote' => 'Counted against the prescription',
        ])
        ->assertOk()
        ->assertJsonPath('data.verifiedByUserId', $checker->id);
});

/**
 * A partial fill is a release: whoever starts handing the medicine over is the
 * dispenser, and completing the fill later must not reassign that identity to
 * whoever happened to finish it.
 */
it('keeps the first releaser as the dispenser across a partial fill', function (): void {
    $starter = pharmacyHardeningMakeUser();
    $finisher = pharmacyHardeningMakeUser();
    $patient = pharmacyHardeningMakePatient();
    pharmacyHardeningEnsureDispensableStock();
    $order = pharmacyHardeningCreateOrder($starter, $patient->id);

    $this->actingAs($starter)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'in_preparation',
        ])
        ->assertOk();

    $this->actingAs($starter)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'partially_dispensed',
            'quantityDispensed' => 5,
        ])
        ->assertOk()
        ->assertJsonPath('data.dispensedByUserId', $starter->id);

    $this->actingAs($finisher)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'dispensed',
            'quantityDispensed' => 12,
        ])
        ->assertOk()
        ->assertJsonPath('data.dispensedByUserId', $starter->id);

    // So the person who finished the fill is still an acceptable checker, and
    // the person who started it is not.
    $this->actingAs($starter)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/verify', [
            'verificationNote' => 'Mine',
        ])
        ->assertUnprocessable();
});

it('still allows verification when the dispenser was never recorded', function (): void {
    $user = pharmacyHardeningMakeUser();
    $patient = pharmacyHardeningMakePatient();
    pharmacyHardeningEnsureDispensableStock();
    $order = pharmacyHardeningCreateOrder($user, $patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'in_preparation',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/status', [
            'status' => 'dispensed',
            'quantityDispensed' => 12,
        ])
        ->assertOk();

    // An order released before the column existed. Refusing these would strand
    // every pre-migration dispense unverified for no gain in safety.
    DB::table('pharmacy_orders')
        ->where('id', $order['id'])
        ->update(['dispensed_by_user_id' => null]);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order['id'].'/verify', [
            'verificationNote' => 'Legacy order',
        ])
        ->assertOk();
});
