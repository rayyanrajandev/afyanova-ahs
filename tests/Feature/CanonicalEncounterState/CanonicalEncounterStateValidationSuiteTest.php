<?php

use App\Modules\Encounter\Application\UseCases\GetEncounterCloseReadinessUseCase;
use App\Modules\Encounter\Domain\Repositories\EncounterAuditLogRepositoryInterface;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use App\Support\CanonicalEncounterState\CanonicalEncounterConflictCode;
use App\Support\CanonicalEncounterState\CanonicalEncounterStateResolver;
use App\Support\CanonicalEncounterState\CanonicalOrdersDimension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Closes the three validation gaps named in the readiness report
 * (reports/encounter-state-machine-design — implementation readiness audit,
 * §3 items 4 and 5, plus explicit CONFLICT-03 coverage). This file does not
 * modify any production code; it only exercises the existing
 * CanonicalEncounterStateResolver against additional fixtures.
 */
function validationSuitePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTVAL'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Validation', 'last_name' => 'Suite', 'gender' => 'female',
        'date_of_birth' => '1990-01-01', 'phone' => '+255700000010', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function validationSuiteEncounter(string $patientId, array $overrides = []): EncounterModel
{
    return EncounterModel::query()->create(array_merge([
        'encounter_number' => 'ENCVAL'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'status' => 'opened',
        'opened_at' => now()->subHour(),
    ], $overrides));
}

function validationSuiteNote(string $patientId, string $encounterId, array $overrides = []): MedicalRecordModel
{
    return MedicalRecordModel::query()->create(array_merge([
        'record_number' => 'MRVAL'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'encounter_at' => now()->subHour(),
        'record_type' => 'consultation_note',
        'subjective' => 'Fixture note.',
        'status' => 'draft',
    ], $overrides));
}

function validationSuiteLabOrder(string $patientId, string $encounterId, array $overrides = []): LaboratoryOrderModel
{
    return LaboratoryOrderModel::query()->create(array_merge([
        'order_number' => 'LABVAL'.strtoupper(Str::random(8)),
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

function validationSuitePharmacyOrder(string $patientId, string $encounterId, array $overrides = []): PharmacyOrderModel
{
    return PharmacyOrderModel::query()->create(array_merge([
        'order_number' => 'RXVAL'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'ordered_at' => now()->subMinutes(20),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => 'Take 1 tablet twice daily',
        'quantity_prescribed' => 10,
        'status' => 'pending',
        'entry_state' => 'active',
    ], $overrides));
}

function validationSuiteRadiologyOrder(string $patientId, string $encounterId, array $overrides = []): RadiologyOrderModel
{
    return RadiologyOrderModel::query()->create(array_merge([
        'order_number' => 'RADVAL'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'ordered_at' => now()->subMinutes(15),
        'modality' => 'XR',
        'study_description' => 'Chest X-ray',
        'status' => 'ordered',
        'entry_state' => 'active',
    ], $overrides));
}

function validationSuiteTheatreProcedure(string $patientId, string $encounterId, array $overrides = []): TheatreProcedureModel
{
    static $operatingClinicianUserId = null;
    $operatingClinicianUserId ??= User::factory()->create()->id;

    return TheatreProcedureModel::query()->create(array_merge([
        'procedure_number' => 'THVAL'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'procedure_type' => 'minor',
        'operating_clinician_user_id' => $operatingClinicianUserId,
        'scheduled_at' => now()->subMinutes(10),
        'status' => 'planned',
        'entry_state' => 'active',
    ], $overrides));
}

// ---------------------------------------------------------------------------
// CONFLICT-09 — real audit-log pattern correctness
// ---------------------------------------------------------------------------

it('detects CONFLICT-09 when the latest audit entry was driven only by the note-sync side channel', function (): void {
    $patient = validationSuitePatient();
    $encounter = validationSuiteEncounter($patient->id, ['status' => 'signed']);
    validationSuiteNote($patient->id, $encounter->id, ['status' => 'finalized', 'signed_at' => now()]);

    app(EncounterAuditLogRepositoryInterface::class)->write(
        encounterId: $encounter->id,
        action: 'encounter.status.updated',
        actorId: null,
        changes: ['after' => ['status' => 'signed']],
        metadata: ['source' => 'medical_record_status', 'medical_record_status' => 'finalized'],
    );

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);

    $codes = array_column($snapshot->detectedConflicts, 'code');
    expect($codes)->toContain(CanonicalEncounterConflictCode::CONFLICT_09->value);
});

it('does not flag CONFLICT-09 when the latest audit entry was an explicit user-initiated action', function (): void {
    $patient = validationSuitePatient();
    $encounter = validationSuiteEncounter($patient->id, ['status' => 'signed']);
    validationSuiteNote($patient->id, $encounter->id, ['status' => 'finalized', 'signed_at' => now()]);

    app(EncounterAuditLogRepositoryInterface::class)->write(
        encounterId: $encounter->id,
        action: 'encounter.status.updated',
        actorId: 42,
        changes: ['after' => ['status' => 'signed']],
        metadata: ['source' => 'explicit_user_action'],
    );

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);

    $codes = array_column($snapshot->detectedConflicts, 'code');
    expect($codes)->not->toContain(CanonicalEncounterConflictCode::CONFLICT_09->value);
});

it('does not flag CONFLICT-09 for legacy statuses outside the signed/amended/in_progress set', function (): void {
    $patient = validationSuitePatient();
    $encounter = validationSuiteEncounter($patient->id, ['status' => 'opened']);

    app(EncounterAuditLogRepositoryInterface::class)->write(
        encounterId: $encounter->id,
        action: 'encounter.opened',
        actorId: null,
        metadata: ['source' => 'medical_record_status'],
    );

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);

    $codes = array_column($snapshot->detectedConflicts, 'code');
    expect($codes)->not->toContain(CanonicalEncounterConflictCode::CONFLICT_09->value);
});

// ---------------------------------------------------------------------------
// Multi-order encounter correctness (high-volume simulation) + query-count ceiling
// ---------------------------------------------------------------------------

it('resolves a high-volume multi-module order encounter correctly within a bounded query count', function (): void {
    $patient = validationSuitePatient();
    $encounter = validationSuiteEncounter($patient->id, ['status' => 'in_progress']);
    validationSuiteNote($patient->id, $encounter->id, ['status' => 'finalized', 'signed_at' => now()]);

    // 25 lab orders, all completed (terminal) — deliberately zero genuinely pending
    // orders of any other kind here, isolating the pharmacy reconciliation_exception
    // below as the only source of pending-orders signal in this fixture.
    for ($i = 0; $i < 25; $i++) {
        validationSuiteLabOrder($patient->id, $encounter->id, ['status' => 'completed']);
    }

    // 15 radiology orders, all completed.
    for ($i = 0; $i < 15; $i++) {
        validationSuiteRadiologyOrder($patient->id, $encounter->id, ['status' => 'completed']);
    }

    // 10 pharmacy orders: 9 dispensed, 1 in reconciliation_exception.
    for ($i = 0; $i < 9; $i++) {
        validationSuitePharmacyOrder($patient->id, $encounter->id, ['status' => 'dispensed']);
    }
    validationSuitePharmacyOrder($patient->id, $encounter->id, ['status' => 'reconciliation_exception']);

    // 8 theatre procedures, all completed.
    for ($i = 0; $i < 8; $i++) {
        validationSuiteTheatreProcedure($patient->id, $encounter->id, ['status' => 'completed']);
    }

    // Measured separately from the resolver: GetEncounterCloseReadinessUseCase is a
    // pre-existing use case the resolver reuses (per the placement design), not new
    // code. Isolating its query count establishes a baseline so the resolver's own
    // incremental cost can be judged on its own, rather than conflating the two.
    DB::enableQueryLog();
    app(GetEncounterCloseReadinessUseCase::class)->execute($encounter->id);
    $closeReadinessAloneQueryCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);
    $resolverFullQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Correctness: one pharmacy reconciliation_exception with zero genuinely pending
    // orders elsewhere => EXCEPTION wins per the mapping's priority (checked before
    // PENDING/RESULTED).
    expect($snapshot->ordersDimension)->toBe(CanonicalOrdersDimension::EXCEPTION);

    // C-11 (reports/clinical-note-audit/15-critical-system-integrity-review.md),
    // fixed: GetEncounterCloseReadinessUseCase no longer treats
    // reconciliation_exception as terminal, so pending_orders now correctly
    // fails here too — CONFLICT-08 (which flagged exactly this masking) can
    // no longer fire.
    $codes = array_column($snapshot->detectedConflicts, 'code');
    expect($codes)->not->toContain(CanonicalEncounterConflictCode::CONFLICT_08->value);

    // KNOWN FINDING (not a resolver defect — see readiness/validation notes):
    // GetEncounterCloseReadinessUseCase itself issues a large, order/candidate-count-
    // scaling number of queries once real Billing charge-capture-candidate volume is
    // involved (measured ~90+ queries for 48 completed orders in this fixture, traced
    // to ListBillingChargeCaptureCandidatesUseCase). That cost is pre-existing and out
    // of this resolver's control. What this resolver owns and must keep small is its
    // OWN incremental cost on top of that baseline.
    $resolverIncrementalQueryCount = $resolverFullQueryCount - $closeReadinessAloneQueryCount;
    expect($resolverIncrementalQueryCount)->toBeLessThanOrEqual(15);
});

it('resolves a high-volume encounter with zero pending orders as RESULTED (not PENDING/EXCEPTION)', function (): void {
    $patient = validationSuitePatient();
    $encounter = validationSuiteEncounter($patient->id, ['status' => 'in_progress']);
    validationSuiteNote($patient->id, $encounter->id, [
        'status' => 'finalized',
        'signed_at' => now(),
        'diagnosis_code' => 'R51',
        'assessment' => 'Tension headache.',
    ]);

    for ($i = 0; $i < 30; $i++) {
        validationSuiteLabOrder($patient->id, $encounter->id, ['status' => 'completed']);
    }

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);

    // O dimension is what this test targets: 30 terminal-status orders, zero pending,
    // zero exceptions => RESULTED. Whether the overall state lands on RULE-7
    // (AWAITING_RESULTS) or RULE-8 (READY_FOR_DISCHARGE) additionally depends on the
    // Billing module's own charge-capture-candidate determination for these completed
    // orders, which is that module's responsibility, not this resolver's, and is
    // deliberately not asserted here.
    expect($snapshot->ordersDimension)->toBe(CanonicalOrdersDimension::RESULTED);
    expect($snapshot->matchedRuleId)->toBeIn(['RULE-7', 'RULE-8']);
});

// ---------------------------------------------------------------------------
// Multi-note encounter correctness (CONFLICT-03 scenarios)
// ---------------------------------------------------------------------------

it('detects CONFLICT-03 when some but not all consultation notes for an encounter are signed', function (): void {
    $patient = validationSuitePatient();
    $encounter = validationSuiteEncounter($patient->id, ['status' => 'in_progress']);
    validationSuiteNote($patient->id, $encounter->id, ['status' => 'finalized', 'signed_at' => now()]);
    validationSuiteNote($patient->id, $encounter->id, ['status' => 'draft']);

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);

    $codes = array_column($snapshot->detectedConflicts, 'code');
    expect($codes)->toContain(CanonicalEncounterConflictCode::CONFLICT_03->value);
});

it('does not flag CONFLICT-03 when every consultation note for the encounter is signed', function (): void {
    $patient = validationSuitePatient();
    $encounter = validationSuiteEncounter($patient->id, ['status' => 'signed']);
    validationSuiteNote($patient->id, $encounter->id, ['status' => 'finalized', 'signed_at' => now()]);
    validationSuiteNote($patient->id, $encounter->id, ['status' => 'amended', 'signed_at' => now()]);

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);

    $codes = array_column($snapshot->detectedConflicts, 'code');
    expect($codes)->not->toContain(CanonicalEncounterConflictCode::CONFLICT_03->value);
});

it('does not flag CONFLICT-03 when every consultation note for the encounter is still draft', function (): void {
    $patient = validationSuitePatient();
    $encounter = validationSuiteEncounter($patient->id, ['status' => 'in_progress']);
    validationSuiteNote($patient->id, $encounter->id, ['status' => 'draft']);
    validationSuiteNote($patient->id, $encounter->id, ['status' => 'draft']);

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);

    $codes = array_column($snapshot->detectedConflicts, 'code');
    expect($codes)->not->toContain(CanonicalEncounterConflictCode::CONFLICT_03->value);
});

it('ignores archived notes when evaluating CONFLICT-03', function (): void {
    $patient = validationSuitePatient();
    $encounter = validationSuiteEncounter($patient->id, ['status' => 'signed']);
    validationSuiteNote($patient->id, $encounter->id, ['status' => 'finalized', 'signed_at' => now()]);
    validationSuiteNote($patient->id, $encounter->id, ['status' => 'archived']);

    $snapshot = app(CanonicalEncounterStateResolver::class)->resolve($encounter->id);

    $codes = array_column($snapshot->detectedConflicts, 'code');
    expect($codes)->not->toContain(CanonicalEncounterConflictCode::CONFLICT_03->value);
});
