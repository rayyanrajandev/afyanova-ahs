<?php

use App\Models\User;
use App\Modules\Laboratory\Application\UseCases\UpdateLaboratoryOrderStatusUseCase;
use App\Modules\Radiology\Application\UseCases\UpdateRadiologyOrderStatusUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\UpdateTheatreProcedureStatusUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function recipeStockPatient(array $overrides = []): array
{
    $patient = array_merge([
        'id' => (string) Str::uuid(),
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'tenant_id' => null,
        'first_name' => 'Asha',
        'last_name' => 'Mosha',
        'gender' => 'female',
        'date_of_birth' => '1990-01-01',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('patients')->insert($patient);

    return $patient;
}

/**
 * @return array<string, mixed>
 */
function recipeStockClinicalItem(string $catalogType, array $overrides = []): array
{
    $codePrefix = match ($catalogType) {
        'radiology_procedure' => 'RAD',
        'theatre_procedure' => 'THR',
        default => 'LAB',
    };

    $item = array_merge([
        'id' => (string) Str::uuid(),
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => $catalogType,
        'code' => $codePrefix.'-'.Str::upper(Str::random(6)),
        'name' => 'Recipe stock governed item',
        'department_id' => null,
        'category' => 'general',
        'unit' => 'service',
        'description' => 'Clinical item for recipe stock consumption tests.',
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'codes' => json_encode(['LOCAL' => $codePrefix.'-TEST'], JSON_THROW_ON_ERROR),
        'status' => 'active',
        'status_reason' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('platform_clinical_catalog_items')->insert($item);

    return $item;
}

/**
 * @return array<string, mixed>
 */
function recipeStockInventoryItem(array $overrides = []): array
{
    $item = array_merge([
        'id' => (string) Str::uuid(),
        'tenant_id' => null,
        'facility_id' => null,
        'item_code' => 'STK-'.Str::upper(Str::random(8)),
        'item_name' => 'Recipe consumable stock',
        'category' => 'medical_consumable',
        'subcategory' => 'procedure_consumable',
        'unit' => 'unit',
        'current_stock' => 10,
        'reorder_level' => 2,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('inventory_items')->insert($item);

    return $item;
}

/**
 * @return array<string, mixed>
 */
function recipeStockRecipeLine(string $catalogItemId, string $inventoryItemId, array $overrides = []): array
{
    $line = array_merge([
        'id' => (string) Str::uuid(),
        'tenant_id' => null,
        'facility_id' => null,
        'clinical_catalog_item_id' => $catalogItemId,
        'inventory_item_id' => $inventoryItemId,
        'quantity_per_order' => 1,
        'unit' => 'unit',
        'waste_factor_percent' => 0,
        'consumption_stage' => 'per_order',
        'is_active' => true,
        'notes' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('clinical_catalog_consumption_recipe_items')->insert($line);

    return $line;
}

/**
 * @return array<string, mixed>
 */
function recipeStockLabOrder(string $patientId, string $catalogItemId, array $overrides = []): array
{
    $order = array_merge([
        'id' => (string) Str::uuid(),
        'order_number' => 'LAB'.now()->format('Ymd').Str::upper(Str::random(6)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patientId,
        'ordered_at' => now(),
        'lab_test_catalog_item_id' => $catalogItemId,
        'test_code' => 'LAB-CBC-001',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'specimen_type' => 'whole_blood',
        'status' => 'in_progress',
        'entry_state' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('laboratory_orders')->insert($order);

    return $order;
}

/**
 * @return array<string, mixed>
 */
function recipeStockRadiologyOrder(string $patientId, string $catalogItemId, array $overrides = []): array
{
    $order = array_merge([
        'id' => (string) Str::uuid(),
        'order_number' => 'RAD'.now()->format('Ymd').Str::upper(Str::random(6)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patientId,
        'ordered_at' => now(),
        'radiology_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => 'RAD-CT-001',
        'modality' => 'CT',
        'study_description' => 'CT Abdomen with contrast',
        'clinical_indication' => 'Abdominal pain',
        'status' => 'in_progress',
        'entry_state' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('radiology_orders')->insert($order);

    return $order;
}

/**
 * @return array<string, mixed>
 */
function recipeStockTheatreProcedure(string $patientId, int $clinicianId, string $catalogItemId, array $overrides = []): array
{
    $procedure = array_merge([
        'id' => (string) Str::uuid(),
        'procedure_number' => 'THR'.now()->format('Ymd').Str::upper(Str::random(6)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patientId,
        'theatre_procedure_catalog_item_id' => $catalogItemId,
        'procedure_type' => 'THR-APP-010',
        'procedure_name' => 'Appendectomy',
        'operating_clinician_user_id' => $clinicianId,
        'theatre_room_name' => 'Theatre A',
        'scheduled_at' => now(),
        'started_at' => now(),
        'status' => 'in_progress',
        'entry_state' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('theatre_procedures')->insert($procedure);

    return $procedure;
}

it('deducts laboratory recipe stock when a lab order is completed', function (): void {
    $user = User::factory()->create();
    $patient = recipeStockPatient();
    $catalogItem = recipeStockClinicalItem('lab_test', [
        'code' => 'LAB-CBC-001',
        'name' => 'Complete Blood Count',
        'unit' => 'test',
    ]);
    $reagent = recipeStockInventoryItem([
        'item_code' => 'LAB-REAG-CBC-KIT',
        'item_name' => 'CBC reagent kit',
        'category' => 'laboratory',
        'unit' => 'kit',
        'current_stock' => 5,
    ]);
    $recipeLine = recipeStockRecipeLine($catalogItem['id'], $reagent['id'], [
        'quantity_per_order' => 0.5,
        'unit' => 'kit',
        'waste_factor_percent' => 10,
        'consumption_stage' => 'processing',
    ]);
    $order = recipeStockLabOrder($patient['id'], $catalogItem['id']);

    $updated = app(UpdateLaboratoryOrderStatusUseCase::class)
        ->execute($order['id'], 'completed', null, 'Normal CBC result', $user->id);

    expect($updated['status'])->toBe('completed');
    expect(round((float) DB::table('inventory_items')->where('id', $reagent['id'])->value('current_stock'), 2))->toBe(4.45);

    $this->assertDatabaseHas('inventory_stock_movements', [
        'item_id' => $reagent['id'],
        'source_type' => 'laboratory_order',
        'source_id' => $order['id'],
        'clinical_catalog_item_id' => $catalogItem['id'],
        'consumption_recipe_item_id' => $recipeLine['id'],
        'movement_type' => 'issue',
    ]);
});

it('deducts radiology recipe stock when a radiology order is completed', function (): void {
    $user = User::factory()->create();
    $patient = recipeStockPatient();
    $catalogItem = recipeStockClinicalItem('radiology_procedure', [
        'code' => 'RAD-CT-ABD-CON',
        'name' => 'CT Abdomen with contrast',
        'unit' => 'study',
    ]);
    $contrast = recipeStockInventoryItem([
        'item_code' => 'RAD-CONTRAST-IOHEXOL',
        'item_name' => 'Iohexol contrast media',
        'category' => 'radiology',
        'unit' => 'vial',
        'current_stock' => 8,
    ]);
    recipeStockRecipeLine($catalogItem['id'], $contrast['id'], [
        'quantity_per_order' => 1,
        'unit' => 'vial',
        'consumption_stage' => 'procedure_completion',
    ]);
    $order = recipeStockRadiologyOrder($patient['id'], $catalogItem['id']);

    app(UpdateRadiologyOrderStatusUseCase::class)
        ->execute($order['id'], 'completed', null, 'CT completed and reported.', $user->id);

    expect((float) DB::table('inventory_items')->where('id', $contrast['id'])->value('current_stock'))->toBe(7.0);
    $this->assertDatabaseHas('inventory_stock_movements', [
        'item_id' => $contrast['id'],
        'source_type' => 'radiology_order',
        'source_id' => $order['id'],
        'movement_type' => 'issue',
    ]);
});

it('deducts theatre recipe stock when a theatre procedure is completed', function (): void {
    $user = User::factory()->create();
    $patient = recipeStockPatient();
    $catalogItem = recipeStockClinicalItem('theatre_procedure', [
        'code' => 'THR-APP-010',
        'name' => 'Appendectomy',
        'unit' => 'procedure',
    ]);
    $gauze = recipeStockInventoryItem([
        'item_code' => 'SUR-GAUZE-STERILE',
        'item_name' => 'Sterile surgical gauze',
        'category' => 'medical_consumable',
        'unit' => 'pack',
        'current_stock' => 20,
    ]);
    recipeStockRecipeLine($catalogItem['id'], $gauze['id'], [
        'quantity_per_order' => 3,
        'unit' => 'pack',
        'consumption_stage' => 'procedure_completion',
    ]);
    $procedure = recipeStockTheatreProcedure($patient['id'], $user->id, $catalogItem['id']);

    app(UpdateTheatreProcedureStatusUseCase::class)
        ->execute($procedure['id'], 'completed', null, null, null, $user->id);

    expect((float) DB::table('inventory_items')->where('id', $gauze['id'])->value('current_stock'))->toBe(17.0);
    $this->assertDatabaseHas('inventory_stock_movements', [
        'item_id' => $gauze['id'],
        'source_type' => 'theatre_procedure',
        'source_id' => $procedure['id'],
        'movement_type' => 'issue',
    ]);
});

it('uses FEFO batch allocation for clinical recipe consumption when tracked batches exist', function (): void {
    $user = User::factory()->create();
    $patient = recipeStockPatient();
    $catalogItem = recipeStockClinicalItem('lab_test', [
        'code' => 'LAB-CBC-FEFO-001',
        'name' => 'CBC FEFO tracked test',
        'unit' => 'test',
    ]);
    $reagent = recipeStockInventoryItem([
        'item_code' => 'LAB-REAG-FEFO-001',
        'item_name' => 'CBC FEFO reagent kit',
        'category' => 'laboratory',
        'unit' => 'kit',
        'current_stock' => 10,
    ]);
    recipeStockRecipeLine($catalogItem['id'], $reagent['id'], [
        'quantity_per_order' => 3,
        'unit' => 'kit',
    ]);
    $earliestBatch = inventoryBatchRecord($reagent['id'], [
        'batch_number' => 'CBC-FEFO-001',
        'expiry_date' => now()->addDays(20)->toDateString(),
        'quantity' => 1,
    ]);
    $laterBatch = inventoryBatchRecord($reagent['id'], [
        'batch_number' => 'CBC-FEFO-002',
        'expiry_date' => now()->addDays(120)->toDateString(),
        'quantity' => 5,
    ]);
    $order = recipeStockLabOrder($patient['id'], $catalogItem['id']);

    app(UpdateLaboratoryOrderStatusUseCase::class)
        ->execute($order['id'], 'completed', null, 'FEFO batch-aware result', $user->id);

    expect((float) DB::table('inventory_items')->where('id', $reagent['id'])->value('current_stock'))->toBe(7.0);
    expect((float) DB::table('inventory_batches')->where('id', $earliestBatch['id'])->value('quantity'))->toBe(0.0);
    expect((float) DB::table('inventory_batches')->where('id', $laterBatch['id'])->value('quantity'))->toBe(3.0);

    $movement = DB::table('inventory_stock_movements')
        ->where('source_type', 'laboratory_order')
        ->where('source_id', $order['id'])
        ->first();

    expect($movement)->not->toBeNull();

    $metadata = json_decode((string) ($movement->metadata ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
    expect($metadata['batchMode'] ?? null)->toBe('tracked');
    expect($metadata['batchAllocationCount'] ?? null)->toBe(2);
    expect($metadata['batchAllocations'][0]['batchId'] ?? null)->toBe($earliestBatch['id']);
    expect((float) ($metadata['batchAllocations'][0]['quantity'] ?? 0))->toBe(1.0);
    expect($metadata['batchAllocations'][1]['batchId'] ?? null)->toBe($laterBatch['id']);
    expect((float) ($metadata['batchAllocations'][1]['quantity'] ?? 0))->toBe(2.0);
});

it('blocks completion and rolls back when recipe stock is insufficient', function (): void {
    $user = User::factory()->create();
    $patient = recipeStockPatient();
    $catalogItem = recipeStockClinicalItem('lab_test', [
        'code' => 'LAB-CBC-001',
        'name' => 'Complete Blood Count',
    ]);
    $reagent = recipeStockInventoryItem([
        'item_code' => 'LAB-REAG-CBC-KIT',
        'item_name' => 'CBC reagent kit',
        'category' => 'laboratory',
        'unit' => 'kit',
        'current_stock' => 0.25,
    ]);
    recipeStockRecipeLine($catalogItem['id'], $reagent['id'], [
        'quantity_per_order' => 1,
        'unit' => 'kit',
    ]);
    $order = recipeStockLabOrder($patient['id'], $catalogItem['id']);

    expect(fn () => app(UpdateLaboratoryOrderStatusUseCase::class)
        ->execute($order['id'], 'completed', null, 'Normal CBC result', $user->id))
        ->toThrow(ValidationException::class);

    expect(DB::table('laboratory_orders')->where('id', $order['id'])->value('status'))->toBe('in_progress');
    expect(round((float) DB::table('inventory_items')->where('id', $reagent['id'])->value('current_stock'), 2))->toBe(0.25);
    $this->assertDatabaseMissing('inventory_stock_movements', [
        'source_type' => 'laboratory_order',
        'source_id' => $order['id'],
    ]);
});
