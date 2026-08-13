<?php

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('reports historical dispense stock backfill candidates in dry run mode without mutating stock', function (): void {
    $patient = makeBackfillPatient();
    $inventoryItem = makeBackfillInventoryItem([
        'item_code' => 'MED-PARA-500TAB',
        'item_name' => 'Paracetamol 500mg',
        'current_stock' => 30,
    ]);

    makeHistoricalDispensedPharmacyOrder($patient->id, [
        'medication_code' => 'MED-PARA-500TAB',
        'medication_name' => 'Paracetamol 500mg',
        'quantity_dispensed' => 12,
    ]);

    $exitCode = Artisan::call('pharmacy:backfill-dispense-stock', [
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    $inventoryItem->refresh();

    expect($exitCode)->toBe(0);
    expect($report['mode'])->toBe('dry_run');
    expect($report['totals']['eligibleOrdersBefore'])->toBe(1);
    expect($report['totals']['processableOrdersInBatch'])->toBe(1);
    expect($report['totals']['ordersBackfilled'])->toBe(0);
    expect((float) $inventoryItem->current_stock)->toBe(30.0);
    expect(InventoryStockMovementModel::query()->count())->toBe(0);
});

it('backfills unresolved historical dispense stock movements and skips already-backed-filled or insufficient cases', function (): void {
    $patient = makeBackfillPatient();

    $processableItem = makeBackfillInventoryItem([
        'item_code' => 'MED-PARA-500TAB',
        'item_name' => 'Paracetamol 500mg',
        'current_stock' => 30,
    ]);
    $existingMovementItem = makeBackfillInventoryItem([
        'item_code' => 'MED-AMOX-500CAP',
        'item_name' => 'Amoxicillin 500mg',
        'current_stock' => 40,
        'unit' => 'capsule',
    ]);
    $insufficientItem = makeBackfillInventoryItem([
        'item_code' => 'MED-IBU-400TAB',
        'item_name' => 'Ibuprofen 400mg',
        'current_stock' => 2,
    ]);

    $processableOrder = makeHistoricalDispensedPharmacyOrder($patient->id, [
        'medication_code' => 'MED-PARA-500TAB',
        'medication_name' => 'Paracetamol 500mg',
        'quantity_dispensed' => 12,
    ]);
    $alreadyBackfilledOrder = makeHistoricalDispensedPharmacyOrder($patient->id, [
        'medication_code' => 'MED-AMOX-500CAP',
        'medication_name' => 'Amoxicillin 500mg',
        'quantity_dispensed' => 5,
    ]);
    $insufficientOrder = makeHistoricalDispensedPharmacyOrder($patient->id, [
        'medication_code' => 'MED-IBU-400TAB',
        'medication_name' => 'Ibuprofen 400mg',
        'quantity_dispensed' => 8,
    ]);

    InventoryStockMovementModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'item_id' => $existingMovementItem->id,
        'movement_type' => 'issue',
        'adjustment_direction' => null,
        'quantity' => 5,
        'quantity_delta' => -5,
        'stock_before' => 45,
        'stock_after' => 40,
        'reason' => 'Existing pharmacy dispense movement.',
        'notes' => null,
        'actor_id' => null,
        'metadata' => [
            'source_module' => 'pharmacy',
            'pharmacy_order_id' => $alreadyBackfilledOrder->id,
        ],
        'occurred_at' => now()->subDay(),
        'created_at' => now()->subDay(),
    ]);

    $exitCode = Artisan::call('pharmacy:backfill-dispense-stock', [
        '--confirm' => true,
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    $processableItem->refresh();
    $existingMovementItem->refresh();
    $insufficientItem->refresh();

    $backfillMovement = InventoryStockMovementModel::query()
        ->get()
        ->first(fn (InventoryStockMovementModel $movement): bool =>
            data_get($movement->metadata, 'source_action') === 'pharmacy-order.backfill-dispense-stock'
        );

    expect($exitCode)->toBe(0);
    expect($report['mode'])->toBe('backfill');
    expect($report['totals']['eligibleOrdersBefore'])->toBe(2);
    expect($report['totals']['alreadyBackfilledOrders'])->toBe(1);
    expect($report['totals']['processableOrdersInBatch'])->toBe(1);
    expect($report['analysis']['insufficientStockInBatch'])->toBe(1);
    expect($report['totals']['ordersBackfilled'])->toBe(1);
    expect((float) $processableItem->current_stock)->toBe(18.0);
    expect((float) $existingMovementItem->current_stock)->toBe(40.0);
    expect((float) $insufficientItem->current_stock)->toBe(2.0);
    expect($backfillMovement?->item_id)->toBe($processableItem->id);
    expect(data_get($backfillMovement?->metadata, 'pharmacy_order_id'))->toBe($processableOrder->id);
    expect(data_get($backfillMovement?->metadata, 'backfill'))->toBeTrue();
    expect($report['processedOrderIds'])->toContain($processableOrder->id);
    expect($report['processedOrderIds'])->not->toContain($insufficientOrder->id);
});

function makeBackfillPatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'middle_name' => null,
        'last_name' => 'John',
        'gender' => 'female',
        'date_of_birth' => '1990-01-15',
        'phone' => '+255700000101',
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

function makeBackfillInventoryItem(array $overrides = []): InventoryItemModel
{
    $attributes = array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'item_code' => 'MED-TEST-001',
        'item_name' => 'Test Medicine',
        'category' => 'pharmaceutical',
        'unit' => 'tablet',
        'current_stock' => 20,
        'reorder_level' => 5,
        'max_stock_level' => 100,
        'status' => 'active',
    ], $overrides);

    $catalogCategory = (string) ($overrides['category'] ?? 'medicine');
    $attributes['category'] = 'pharmaceutical';
    $attributes['clinical_catalog_item_id'] = $attributes['clinical_catalog_item_id'] ?? ClinicalCatalogItemModel::query()->firstOrCreate(
        [
            'tenant_id' => $attributes['tenant_id'],
            'facility_id' => $attributes['facility_id'],
            'catalog_type' => 'formulary_item',
            'code' => $attributes['item_code'],
        ],
        [
            'name' => $attributes['item_name'],
            'department_id' => null,
            'category' => $catalogCategory,
            'unit' => $attributes['unit'],
            'description' => 'Auto-linked pharmacy stock backfill test fixture.',
            'metadata' => null,
            'status' => 'active',
            'status_reason' => null,
        ],
    )->id;

    return InventoryItemModel::query()->create($attributes);
}

function makeHistoricalDispensedPharmacyOrder(string $patientId, array $overrides = []): PharmacyOrderModel
{
    return PharmacyOrderModel::query()->create(array_merge([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patientId,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subDays(3),
        'medication_code' => 'MED-TEST-001',
        'medication_name' => 'Test Medicine',
        'dosage_instruction' => 'Take 1 tablet twice daily',
        'quantity_prescribed' => 12,
        'quantity_dispensed' => 12,
        'dispensing_notes' => 'Historical dispensed order before depletion update.',
        'dispensed_at' => now()->subDays(2),
        'verified_at' => null,
        'verified_by_user_id' => null,
        'verification_note' => null,
        'formulary_decision_status' => 'formulary',
        'formulary_decision_reason' => null,
        'formulary_reviewed_at' => null,
        'formulary_reviewed_by_user_id' => null,
        'substitution_allowed' => false,
        'substitution_made' => false,
        'substituted_medication_code' => null,
        'substituted_medication_name' => null,
        'substitution_reason' => null,
        'substitution_approved_at' => null,
        'substitution_approved_by_user_id' => null,
        'reconciliation_status' => 'pending',
        'reconciliation_note' => '',
        'reconciled_at' => null,
        'reconciled_by_user_id' => null,
        'status' => 'dispensed',
        'status_reason' => null,
    ], $overrides));
}
