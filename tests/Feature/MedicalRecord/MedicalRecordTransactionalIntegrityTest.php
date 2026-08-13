<?php

use App\Modules\Encounter\Application\Services\EncounterLifecycleService;
use App\Modules\Encounter\Application\UseCases\GetEncounterCloseReadinessUseCase;
use App\Modules\Encounter\Domain\Repositories\EncounterAuditLogRepositoryInterface;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\MedicalRecord\Application\UseCases\UpdateMedicalRecordStatusUseCase;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Regression coverage for the C-7 fix
 * (reports/clinical-note-audit/15-critical-system-integrity-review.md):
 * UpdateMedicalRecordStatusUseCase/UpdateMedicalRecordUseCase previously ran
 * the primary write, audit log, version snapshot, and encounter-status sync
 * as four independently-committed operations. A failure between steps (e.g.
 * the encounter sync throwing after the note's status had already committed
 * as finalized) left the note and its encounter permanently out of sync,
 * with no compensating transaction to detect or correct the mismatch. The
 * fix wraps the whole sequence in one DB::transaction(), so a failure
 * anywhere in it rolls back everything — including the row-locked primary
 * write that used to look already-committed by the time the failure
 * occurred.
 *
 * The encounter-sync step is forced to fail here via a swapped-in
 * EncounterLifecycleService subclass, since it's the last step in the
 * sequence and therefore the strongest proof that everything before it
 * (which would otherwise already look committed) rolls back too.
 */
function transactionalIntegrityPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTTXN'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Transactional', 'last_name' => 'Integrity', 'gender' => 'female',
        'date_of_birth' => '1992-02-02', 'phone' => '+255700000015', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function transactionalIntegrityEncounter(string $patientId): EncounterModel
{
    return EncounterModel::query()->create([
        'encounter_number' => 'ENCTXN'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'status' => 'opened',
        'type' => 'outpatient',
        'opened_at' => now(),
    ]);
}

function transactionalIntegrityNote(string $patientId, string $encounterId): MedicalRecordModel
{
    return MedicalRecordModel::query()->create([
        'record_number' => 'MRTXN'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'encounter_at' => now()->subHour(),
        'record_type' => 'consultation_note',
        'subjective' => 'Fixture note.',
        'status' => 'draft',
    ]);
}

function bindFailingEncounterLifecycleService(): void
{
    $failingLifecycleService = new class(
        app(GetEncounterCloseReadinessUseCase::class),
        app(EncounterAuditLogRepositoryInterface::class),
    ) extends EncounterLifecycleService {
        public function syncFromMedicalRecordStatus(
            string $encounterId,
            string $medicalRecordStatus,
            ?string $reason = null,
            ?int $actorId = null,
        ): ?EncounterModel {
            throw new RuntimeException('Simulated failure after the primary write, before commit.');
        }
    };

    app()->instance(EncounterLifecycleService::class, $failingLifecycleService);
}

it('rolls back the primary write, audit log, and version snapshot when the encounter sync step fails', function (): void {
    $patient = transactionalIntegrityPatient();
    $encounter = transactionalIntegrityEncounter($patient->id);
    $note = transactionalIntegrityNote($patient->id, $encounter->id);

    bindFailingEncounterLifecycleService();

    expect(fn () => app(UpdateMedicalRecordStatusUseCase::class)->execute(
        id: $note->id,
        status: 'finalized',
        reason: null,
    ))->toThrow(RuntimeException::class);

    expect(DB::table('medical_records')->where('id', $note->id)->value('status'))->toBe('draft');
    expect(DB::table('medical_records')->where('id', $note->id)->value('signed_at'))->toBeNull();
    expect(DB::table('medical_record_audit_logs')->where('medical_record_id', $note->id)->count())->toBe(0);
    expect(DB::table('medical_record_versions')->where('medical_record_id', $note->id)->count())->toBe(0);
    expect(DB::table('encounters')->where('id', $encounter->id)->value('status'))->toBe('opened');
});

it('still finalizes and syncs the encounter end-to-end when nothing fails', function (): void {
    $patient = transactionalIntegrityPatient();
    $encounter = transactionalIntegrityEncounter($patient->id);
    $note = transactionalIntegrityNote($patient->id, $encounter->id);

    $updated = app(UpdateMedicalRecordStatusUseCase::class)->execute(
        id: $note->id,
        status: 'finalized',
        reason: null,
    );

    expect($updated['status'])->toBe('finalized');
    expect(DB::table('medical_record_audit_logs')->where('medical_record_id', $note->id)->count())->toBeGreaterThan(0);
    expect(DB::table('encounters')->where('id', $encounter->id)->value('status'))->toBe('signed');
});
