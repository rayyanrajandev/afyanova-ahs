<?php

use App\Modules\MedicalRecord\Application\Exceptions\InvalidMedicalRecordStatusTransitionException;
use App\Modules\MedicalRecord\Application\UseCases\UpdateMedicalRecordStatusUseCase;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Regression coverage for the C-1 fix (reports/clinical-note-audit/15-critical-system-integrity-review.md):
 * the status-update path previously validated business rules (transition legality,
 * consultation ownership) against a value read *before* any row lock was taken, then
 * wrote through a separate, unlocked call. A concurrent writer could change the row
 * in between. The fix moves validation inside MedicalRecordRepositoryInterface::updateWithLock(),
 * so it runs against the row as read under `SELECT ... FOR UPDATE`, in the same
 * transaction as the write.
 *
 * These tests do not attempt true multi-process concurrency (not feasible in a
 * synchronous test process). Instead they simulate "a concurrent writer already
 * changed the row" via a raw DB update performed *after* the fixture is created but
 * *before* the method under test runs, then prove the method under test sees that
 * change rather than a stale snapshot.
 */
function raceConditionPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTRACE'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Race', 'last_name' => 'Condition', 'gender' => 'female',
        'date_of_birth' => '1990-01-01', 'phone' => '+255700000012', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function raceConditionNote(string $patientId, array $overrides = []): MedicalRecordModel
{
    return MedicalRecordModel::query()->create(array_merge([
        'record_number' => 'MRRACE'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'encounter_at' => now()->subHour(),
        'record_type' => 'consultation_note',
        'subjective' => 'Fixture note.',
        'status' => 'draft',
    ], $overrides));
}

it('reads the row under lock, not a stale pre-lock snapshot', function (): void {
    $patient = raceConditionPatient();
    $note = raceConditionNote($patient->id, ['status' => 'draft']);

    // Simulate a concurrent writer that already archived this note via a raw update,
    // bypassing the application layer entirely (equivalent to a second request's
    // transaction having already committed by the time this one acquires its lock).
    DB::table('medical_records')->where('id', $note->id)->update(['status' => 'archived']);

    $seen = null;
    app(MedicalRecordRepositoryInterface::class)->updateWithLock($note->id, function (array $existing) use (&$seen): array {
        $seen = $existing['status'];

        return ['status_reason' => 'no-op probe'];
    });

    expect($seen)->toBe('archived');
});

it('validates the requested transition against the current row state, not a stale read', function (): void {
    $patient = raceConditionPatient();
    $note = raceConditionNote($patient->id, ['status' => 'draft']);

    // Concurrent writer archives the note between "request initiated" and "lock acquired".
    DB::table('medical_records')->where('id', $note->id)->update(['status' => 'archived']);

    // A client still believing the note is 'draft' requests 'finalized'. archived -> finalized
    // is not an allowed transition, and the fix must reject it based on the *current*
    // (archived) state, not the stale 'draft' state that existed when the request began.
    expect(fn () => app(UpdateMedicalRecordStatusUseCase::class)->execute(
        id: $note->id,
        status: 'finalized',
        reason: null,
    ))->toThrow(InvalidMedicalRecordStatusTransitionException::class);

    // And the row must be unchanged — the transaction rolled back, nothing partially applied.
    expect(DB::table('medical_records')->where('id', $note->id)->value('status'))->toBe('archived');
});

it('still finalizes a draft note correctly end-to-end after the fix', function (): void {
    $actor = User::factory()->create();
    $patient = raceConditionPatient();
    $note = raceConditionNote($patient->id, ['status' => 'draft']);

    $updated = app(UpdateMedicalRecordStatusUseCase::class)->execute(
        id: $note->id,
        status: 'finalized',
        reason: null,
        actorId: $actor->id,
    );

    expect($updated)->not->toBeNull();
    expect($updated['status'])->toBe('finalized');
    expect($updated['signed_by_user_id'])->toBe($actor->id);
    expect($updated['signed_at'])->not->toBeNull();
});

it('applies the finalize-after-sign override using the current signed_at, not a stale value', function (): void {
    $priorSigner = User::factory()->create();
    $secondActor = User::factory()->create();
    $patient = raceConditionPatient();
    $note = raceConditionNote($patient->id, ['status' => 'draft', 'signed_at' => null]);

    // Simulate a concurrent prior finalize that already set signed_at, without this
    // process's own initial read having seen it.
    DB::table('medical_records')->where('id', $note->id)->update([
        'status' => 'finalized',
        'signed_at' => now()->subMinutes(5),
        'signed_by_user_id' => $priorSigner->id,
    ]);

    // A second finalize request (e.g. a retried/duplicate submission) must see the
    // already-signed state and apply the amend-after-sign override, not treat it as
    // a fresh first-time finalize.
    $updated = app(UpdateMedicalRecordStatusUseCase::class)->execute(
        id: $note->id,
        status: 'finalized',
        reason: null,
        actorId: $secondActor->id,
    );

    expect($updated['status'])->toBe('amended');
});

it('returns null without writing when the record does not exist', function (): void {
    $updated = app(UpdateMedicalRecordStatusUseCase::class)->execute(
        id: (string) Str::uuid(),
        status: 'finalized',
        reason: null,
    );

    expect($updated)->toBeNull();
});
