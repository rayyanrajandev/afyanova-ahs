<?php

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Regression coverage for the C-4 fix
 * (reports/clinical-note-audit/15-critical-system-integrity-review.md):
 * EncounterResolverService::findOrCreateForVisit() previously ran a
 * check-then-create with no DB constraint behind it, so two near-simultaneous
 * resolutions for the same appointment/admission could each create their own
 * encounter row. The fix adds unique indexes on encounters.appointment_id/
 * admission_id and catches the resulting UniqueConstraintViolationException,
 * returning whichever row the concurrent writer actually committed.
 *
 * True multi-process concurrency isn't feasible in a synchronous test
 * process. Instead, an `EncounterModel::creating()` hook inserts the
 * "concurrent winner" row at the exact moment the resolver is about to
 * INSERT its own — i.e. after its own read already found nothing, mirroring
 * how the real race is exploitable in production.
 */
function resolverConcurrencyPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTCONC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Concurrency', 'last_name' => 'Race', 'gender' => 'male',
        'date_of_birth' => '1988-08-08', 'phone' => '+255700000014', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function resolverConcurrencyAppointment(string $patientId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APTCONC'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'completed',
    ]);
}

function insertConcurrentEncounter(string $patientId, string $appointmentId): void
{
    DB::table('encounters')->insert([
        'id' => (string) Str::uuid(),
        'encounter_number' => 'ENCCONC'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'admission_id' => null,
        'status' => 'opened',
        'type' => 'outpatient',
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('recovers the concurrently-created encounter instead of throwing or duplicating', function (): void {
    $patient = resolverConcurrencyPatient();
    $appointment = resolverConcurrencyAppointment($patient->id);

    EncounterModel::creating(function () use ($patient, $appointment): void {
        insertConcurrentEncounter($patient->id, $appointment->id);
    });

    $encounter = app(EncounterResolverService::class)->findOrCreateForVisit(
        patientId: $patient->id,
        appointmentId: $appointment->id,
        admissionId: null,
        actorId: null,
    );

    expect($encounter->appointment_id)->toBe($appointment->id);
    expect(EncounterModel::query()->where('appointment_id', $appointment->id)->count())->toBe(1);
});

it('enforces one encounter per appointment at the database level', function (): void {
    $patient = resolverConcurrencyPatient();
    $appointment = resolverConcurrencyAppointment($patient->id);

    insertConcurrentEncounter($patient->id, $appointment->id);

    expect(fn () => insertConcurrentEncounter($patient->id, $appointment->id))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('still resolves the existing encounter on the fast path when no race occurs', function (): void {
    $patient = resolverConcurrencyPatient();
    $appointment = resolverConcurrencyAppointment($patient->id);

    $first = app(EncounterResolverService::class)->findOrCreateForVisit(
        patientId: $patient->id,
        appointmentId: $appointment->id,
        admissionId: null,
        actorId: null,
    );

    $second = app(EncounterResolverService::class)->findOrCreateForVisit(
        patientId: $patient->id,
        appointmentId: $appointment->id,
        admissionId: null,
        actorId: null,
    );

    expect($second->id)->toBe($first->id);
    expect(EncounterModel::query()->where('appointment_id', $appointment->id)->count())->toBe(1);
});
