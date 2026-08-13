<?php

use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitModel;
use App\Modules\Laboratory\Application\UseCases\UpdateLaboratoryOrderStatusUseCase;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\PatientFlow\Application\Listeners\LogOrderCompletionForOrderingClinician;
use App\Modules\Pharmacy\Application\UseCases\UpdatePharmacyOrderStatusUseCase;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Application\UseCases\UpdateRadiologyOrderStatusUseCase;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Phase 3 (Mode A shadow logging) of
 * reports/queue-based-workflow-modernization-plan.md. Reuses
 * makePatientFlowPatient()/makePatientFlowAppointment() from
 * GetActiveVisitJourneyUseCaseTest.php (Pest shares global function scope
 * across sibling test files run in the same directory).
 */
function makeSignedLabOrder(string $patientId, string $appointmentId, string $status): LaboratoryOrderModel
{
    return LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'ordered_at' => now(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'status' => $status,
        'entry_state' => 'active',
        'lifecycle_locked_at' => now(),
    ]);
}

function makeSignedRadiologyOrder(string $patientId, string $appointmentId, string $status): RadiologyOrderModel
{
    return RadiologyOrderModel::query()->create([
        'order_number' => 'RAD'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'ordered_at' => now(),
        'modality' => 'xray',
        'study_description' => 'Chest X-Ray (PA)',
        'status' => $status,
        'entry_state' => 'active',
        'lifecycle_locked_at' => now(),
    ]);
}

function makeSignedPharmacyOrder(string $patientId, string $appointmentId, string $status): PharmacyOrderModel
{
    $catalogItem = \App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel::query()->firstOrCreate(
        [
            'tenant_id' => null,
            'facility_id' => null,
            'catalog_type' => 'formulary_item',
            'code' => 'ATC:N02BE01',
        ],
        [
            'name' => 'Paracetamol 500mg',
            'department_id' => null,
            'category' => 'analgesics',
            'unit' => 'tablet',
            'description' => 'Shadow-automation test fixture',
            'metadata' => null,
            'status' => 'active',
            'status_reason' => null,
        ],
    );

    InventoryItemModel::query()->firstOrCreate(
        ['item_code' => 'ATC:N02BE01'],
        [
            'item_name' => 'Paracetamol 500mg',
            'category' => 'pharmaceutical',
            'unit' => 'tablet',
            'current_stock' => 480,
            'reorder_level' => 120,
            'max_stock_level' => 800,
            'status' => 'active',
            'clinical_catalog_item_id' => $catalogItem->id,
        ],
    );
    $inventoryItem = InventoryItemModel::query()->where('item_code', 'ATC:N02BE01')->firstOrFail();

    InventoryItemUnitModel::query()->firstOrCreate(
        ['item_id' => $inventoryItem->id, 'unit_name' => 'tablet'],
        [
            'unit_code' => 'tab',
            'base_quantity' => 1,
            'is_base_unit' => true,
            'is_active' => true,
        ],
    );

    return PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'ordered_at' => now(),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => 'Take 1 tablet every 8 hours',
        'quantity_prescribed' => 12,
        'quantity_dispensed' => 0,
        'status' => $status,
        'entry_state' => 'active',
        'lifecycle_locked_at' => now(),
        'formulary_decision_status' => 'approved',
    ]);
}

it('logs the shadow notification for a completed lab order, with the visit now with_clinician', function (): void {
    Log::shouldReceive('channel')
        ->once()
        ->with('patient_flow_shadow_automation')
        ->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->with(
            'Mode A shadow: would notify the ordering clinician that this order is complete',
            \Mockery::on(fn (array $context): bool => $context['mode'] === 'A'
                && $context['proposed_action'] === 'notify_ordering_clinician'
                && $context['source_event'] === 'laboratory_order_completed'
                && $context['derived_visit_journey_step'] === 'with_clinician'),
        );

    $clinician = User::factory()->create();
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    $order = makeSignedLabOrder($patient->id, $appointment->id, 'in_progress');

    app(UpdateLaboratoryOrderStatusUseCase::class)->execute(
        id: $order->id,
        status: 'completed',
        reason: null,
        resultSummary: 'Normal',
        actorId: $clinician->id,
    );
});

it('logs the shadow notification for a dispensed pharmacy order', function (): void {
    Log::shouldReceive('channel')
        ->once()
        ->with('patient_flow_shadow_automation')
        ->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->with(
            'Mode A shadow: would notify the ordering clinician that this order is complete',
            \Mockery::on(fn (array $context): bool => $context['source_event'] === 'pharmacy_order_dispensed'),
        );

    $clinician = User::factory()->create();
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    $order = makeSignedPharmacyOrder($patient->id, $appointment->id, 'in_preparation');

    app(UpdatePharmacyOrderStatusUseCase::class)->execute(
        id: $order->id,
        status: 'dispensed',
        reason: null,
        quantityDispensed: null,
        dispensedUnit: null,
        dispensingNotes: 'Dispensed in full.',
        actorId: $clinician->id,
    );
});

it('logs the shadow notification for a completed radiology order', function (): void {
    Log::shouldReceive('channel')
        ->once()
        ->with('patient_flow_shadow_automation')
        ->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->with(
            'Mode A shadow: would notify the ordering clinician that this order is complete',
            \Mockery::on(fn (array $context): bool => $context['source_event'] === 'radiology_order_completed'),
        );

    $clinician = User::factory()->create();
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    $order = makeSignedRadiologyOrder($patient->id, $appointment->id, 'in_progress');

    app(UpdateRadiologyOrderStatusUseCase::class)->execute(
        id: $order->id,
        status: 'completed',
        reason: null,
        reportSummary: 'Clear.',
        actorId: $clinician->id,
    );
});

it('does not log anything for a non-completing lab transition', function (): void {
    Log::shouldReceive('channel')->never();

    $clinician = User::factory()->create();
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
    ]);
    $order = makeSignedLabOrder($patient->id, $appointment->id, 'ordered');

    app(UpdateLaboratoryOrderStatusUseCase::class)->execute(
        id: $order->id,
        status: 'collected',
        reason: null,
        resultSummary: null,
        actorId: $clinician->id,
    );
});

it('swallows a logging failure rather than letting it propagate', function (): void {
    Log::shouldReceive('channel')
        ->once()
        ->with('patient_flow_shadow_automation')
        ->andThrow(new RuntimeException('log channel unavailable'));

    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, ['status' => 'in_consultation']);

    $event = new App\Modules\Laboratory\Domain\Events\LaboratoryOrderCompleted(
        laboratoryOrderId: (string) Str::uuid(),
        patientId: $patient->id,
        appointmentId: $appointment->id,
        orderedByUserId: null,
        actorId: null,
    );

    expect(fn () => app(LogOrderCompletionForOrderingClinician::class)->handleLaboratoryOrderCompleted($event))
        ->not->toThrow(RuntimeException::class);
});
