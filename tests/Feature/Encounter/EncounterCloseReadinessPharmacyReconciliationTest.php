<?php

use App\Modules\Encounter\Application\UseCases\GetEncounterCloseReadinessUseCase;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Regression coverage for the C-11 fix
 * (reports/clinical-note-audit/15-critical-system-integrity-review.md):
 * a pharmacy order in `reconciliation_exception` — an unresolved-problem
 * state, not a safe end-state — used to be grouped with dispensed/cancelled
 * in GetEncounterCloseReadinessUseCase::pharmacyTerminalStatuses(), so it
 * silently stopped contributing to the "pending clinical orders"
 * close-readiness item the moment it was raised. It must now count as
 * pending until resolved.
 */
function closeReadinessReconciliationPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTRECON'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Reconciliation', 'last_name' => 'Exception', 'gender' => 'male',
        'date_of_birth' => '1980-03-03', 'phone' => '+255700000016', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function closeReadinessReconciliationEncounter(string $patientId): EncounterModel
{
    return EncounterModel::query()->create([
        'encounter_number' => 'ENCRECON'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'status' => 'opened',
        'type' => 'outpatient',
        'opened_at' => now(),
    ]);
}

function closeReadinessReconciliationPharmacyOrder(string $patientId, string $encounterId, string $status): PharmacyOrderModel
{
    return PharmacyOrderModel::query()->create([
        'order_number' => 'RXRECON'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'ordered_at' => now()->subMinutes(20),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => 'Take 1 tablet twice daily',
        'quantity_prescribed' => 10,
        'status' => $status,
        'entry_state' => 'active',
    ]);
}

it('reports the pending_orders item as failing when a pharmacy order is in reconciliation_exception', function (): void {
    $patient = closeReadinessReconciliationPatient();
    $encounter = closeReadinessReconciliationEncounter($patient->id);
    closeReadinessReconciliationPharmacyOrder($patient->id, $encounter->id, 'reconciliation_exception');

    $readiness = app(GetEncounterCloseReadinessUseCase::class)->execute($encounter->id);

    $pendingOrdersItem = collect($readiness['items'])->firstWhere('id', 'pending_orders');

    expect($pendingOrdersItem['status'])->toBe('fail');
    expect($pendingOrdersItem['count'])->toBe(1);
});

it('still treats dispensed and reconciliation_completed pharmacy orders as resolved', function (): void {
    $patient = closeReadinessReconciliationPatient();
    $encounter = closeReadinessReconciliationEncounter($patient->id);
    closeReadinessReconciliationPharmacyOrder($patient->id, $encounter->id, 'dispensed');
    closeReadinessReconciliationPharmacyOrder($patient->id, $encounter->id, 'reconciliation_completed');

    $readiness = app(GetEncounterCloseReadinessUseCase::class)->execute($encounter->id);

    $pendingOrdersItem = collect($readiness['items'])->firstWhere('id', 'pending_orders');

    expect($pendingOrdersItem['status'])->toBe('pass');
    expect($pendingOrdersItem['count'])->toBe(0);
});
