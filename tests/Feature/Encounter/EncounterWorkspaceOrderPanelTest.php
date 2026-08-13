<?php

use App\Modules\Encounter\Application\UseCases\GetEncounterWorkspaceUseCase;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Regression coverage for the C-8 fix
 * (reports/clinical-note-audit/15-critical-system-integrity-review.md):
 * GetEncounterWorkspaceUseCase::CARE_ARTIFACT_LIMIT caps each order-type
 * panel at 6 rows, previously sorted newest-first — so an old *pending*
 * order (exactly the one most overdue for follow-up) could be silently
 * pushed out of the visible panel by newer *completed* rows. The fix sorts
 * pending-first within the cap, and separately exposes an uncapped pending
 * count for a "+N more pending" affordance.
 */
function orderPanelPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTOP'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Order', 'last_name' => 'Panel', 'gender' => 'male',
        'date_of_birth' => '1987-07-07', 'phone' => '+255700000020', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function orderPanelEncounter(string $patientId): EncounterModel
{
    return EncounterModel::query()->create([
        'encounter_number' => 'ENCOP'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'status' => 'opened',
        'type' => 'outpatient',
        'opened_at' => now(),
    ]);
}

function orderPanelLabOrder(string $patientId, string $encounterId, array $overrides = []): LaboratoryOrderModel
{
    return LaboratoryOrderModel::query()->create(array_merge([
        'order_number' => 'LABOP'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'ordered_at' => now()->subMinutes(30),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'status' => 'ordered',
        'entry_state' => 'active',
    ], $overrides));
}

it('never lets an old pending order be pushed out of the panel by newer completed ones', function (): void {
    $patient = orderPanelPatient();
    $encounter = orderPanelEncounter($patient->id);

    // One old pending order, then 6 newer completed orders — enough to fill
    // the cap on recency alone. Before the fix, the pending order (the one
    // actually needing follow-up) would have been the first one dropped.
    $oldestPending = orderPanelLabOrder($patient->id, $encounter->id, [
        'status' => 'ordered',
        'ordered_at' => now()->subDays(3),
    ]);

    for ($i = 0; $i < 6; $i++) {
        orderPanelLabOrder($patient->id, $encounter->id, [
            'status' => 'completed',
            'ordered_at' => now()->subMinutes($i),
        ]);
    }

    $workspace = app(GetEncounterWorkspaceUseCase::class)->execute($encounter->id);

    $labOrderIds = collect($workspace['laboratoryOrders'])->pluck('id')->all();

    expect($workspace['laboratoryOrders'])->toHaveCount(6);
    expect($labOrderIds)->toContain($oldestPending->id);
    // Pending sorts first, ahead of every completed row regardless of recency.
    expect($workspace['laboratoryOrders'][0]['id'])->toBe($oldestPending->id);
});

it('exposes the true pending count independent of the display cap', function (): void {
    $patient = orderPanelPatient();
    $encounter = orderPanelEncounter($patient->id);

    for ($i = 0; $i < 8; $i++) {
        orderPanelLabOrder($patient->id, $encounter->id, [
            'status' => 'ordered',
            'ordered_at' => now()->subMinutes($i),
        ]);
    }

    $workspace = app(GetEncounterWorkspaceUseCase::class)->execute($encounter->id);

    // Cap still applies to the visible list...
    expect($workspace['laboratoryOrders'])->toHaveCount(6);
    // ...but the true pending count is not silently truncated to the cap.
    expect($workspace['laboratoryOrdersPendingCount'])->toBe(8);
});

it('still sorts newest-first within the pending tier and within the completed tier', function (): void {
    $patient = orderPanelPatient();
    $encounter = orderPanelEncounter($patient->id);

    $olderPending = orderPanelLabOrder($patient->id, $encounter->id, [
        'status' => 'ordered', 'ordered_at' => now()->subHours(2),
    ]);
    $newerPending = orderPanelLabOrder($patient->id, $encounter->id, [
        'status' => 'ordered', 'ordered_at' => now()->subHours(1),
    ]);
    $olderCompleted = orderPanelLabOrder($patient->id, $encounter->id, [
        'status' => 'completed', 'ordered_at' => now()->subMinutes(30),
    ]);
    $newerCompleted = orderPanelLabOrder($patient->id, $encounter->id, [
        'status' => 'completed', 'ordered_at' => now()->subMinutes(10),
    ]);

    $workspace = app(GetEncounterWorkspaceUseCase::class)->execute($encounter->id);
    $orderedIds = collect($workspace['laboratoryOrders'])->pluck('id')->all();

    expect($orderedIds)->toBe([
        $newerPending->id,
        $olderPending->id,
        $newerCompleted->id,
        $olderCompleted->id,
    ]);
});

it('reports zero pending count when no lab orders are pending', function (): void {
    $patient = orderPanelPatient();
    $encounter = orderPanelEncounter($patient->id);

    orderPanelLabOrder($patient->id, $encounter->id, ['status' => 'completed']);
    orderPanelLabOrder($patient->id, $encounter->id, ['status' => 'cancelled']);

    $workspace = app(GetEncounterWorkspaceUseCase::class)->execute($encounter->id);

    expect($workspace['laboratoryOrdersPendingCount'])->toBe(0);
});
