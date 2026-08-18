<?php

/**
 * The clinician queue is a query, not a slice of the first page.
 *
 * The stage rule used to live in the browser, written twice, and testing
 * encounter statuses that do not exist — "admitted", "completed", "resolved",
 * "in_consultation" and "open" are none of them EncounterStatus values. Every
 * one of those comparisons was dead, and the list itself was whatever the
 * server's default page of 15 happened to contain, sliced four ways.
 */

use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Application\UseCases\ListClinicianQueueStageCountsUseCase;
use App\Modules\Encounter\Application\UseCases\ListEncountersUseCase;
use App\Modules\Encounter\Domain\ValueObjects\ClinicianQueueStage;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function clinicianStagePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTQS'.strtoupper(Str::random(8)),
        'first_name' => 'Queue',
        'last_name' => 'Stage',
        'gender' => 'female',
        'date_of_birth' => '1990-05-05',
        'phone' => '+2557'.random_int(10000000, 99999999),
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function clinicianStageAppointment(string $patientId, string $status, ?string $triagedAt = null): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APTQS'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour(),
        'duration_minutes' => 30,
        'reason' => 'Visit',
        'status' => $status,
        'triaged_at' => $triagedAt,
    ]);
}

function clinicianStageEncounter(string $patientId, array $overrides = []): EncounterModel
{
    return EncounterModel::query()->create(array_merge([
        'encounter_number' => 'ENCQS'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'status' => 'opened',
        'type' => 'outpatient',
        'opened_at' => now(),
    ], $overrides));
}

/**
 * @return array<int, string>
 */
function encounterIdsForStage(string $stage): array
{
    $result = app(ListEncountersUseCase::class)->execute([
        'queueStage' => $stage,
        'perPage' => 100,
    ]);

    return array_map(static fn (array $row): string => (string) $row['id'], $result['data']);
}

it('puts a triaged patient waiting for the doctor in waiting_provider', function (): void {
    $patient = clinicianStagePatient();
    $appointment = clinicianStageAppointment($patient->id, 'waiting_provider', now()->subMinutes(20)->toDateTimeString());
    $encounter = clinicianStageEncounter($patient->id, ['appointment_id' => $appointment->id]);

    expect(encounterIdsForStage('waiting_provider'))->toContain($encounter->id);
    expect(encounterIdsForStage('in_consultation'))->not->toContain($encounter->id);
});

it('shows a walk-in with no appointment — the case that belonged to no tab', function (): void {
    // Its only route into a pile was `status === "open"`, and the real value is
    // "opened", so it was fetched from the server and then silently dropped.
    $patient = clinicianStagePatient();
    $encounter = clinicianStageEncounter($patient->id, ['appointment_id' => null, 'status' => 'opened']);

    expect(encounterIdsForStage('waiting_provider'))->toContain($encounter->id);
});

it('moves a patient in the room out of waiting, despite triage still being stamped', function (): void {
    // triaged_at stays set for the rest of the visit, so a naive "has been
    // triaged" rule keeps an in-consultation patient in the waiting pile too.
    $patient = clinicianStagePatient();
    $appointment = clinicianStageAppointment($patient->id, 'in_consultation', now()->subMinutes(30)->toDateTimeString());
    $encounter = clinicianStageEncounter($patient->id, ['appointment_id' => $appointment->id]);

    expect(encounterIdsForStage('in_consultation'))->toContain($encounter->id);
    expect(encounterIdsForStage('waiting_provider'))->not->toContain($encounter->id);
});

it('treats an admitted patient as admitted whatever the appointment says', function (): void {
    $patient = clinicianStagePatient();
    $appointment = clinicianStageAppointment($patient->id, 'in_consultation', now()->subHour()->toDateTimeString());
    $admission = AdmissionModel::query()->create([
        'admission_number' => 'ADMQS'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'ward' => 'Ward A',
        'bed' => 'A-1',
        'admitted_at' => now()->subMinutes(10),
        'admission_reason' => 'Observation',
        'status' => 'admitted',
    ]);
    $encounter = clinicianStageEncounter($patient->id, [
        'appointment_id' => $appointment->id,
        'admission_id' => $admission->id,
    ]);

    expect(encounterIdsForStage('admitted'))->toContain($encounter->id);
    expect(encounterIdsForStage('in_consultation'))->not->toContain($encounter->id);
});

it('closes out a finished visit', function (): void {
    $patient = clinicianStagePatient();
    $appointment = clinicianStageAppointment($patient->id, 'completed', now()->subHour()->toDateTimeString());
    $encounter = clinicianStageEncounter($patient->id, ['appointment_id' => $appointment->id, 'status' => 'closed']);

    expect(encounterIdsForStage('completed'))->toContain($encounter->id);
    foreach (['waiting_provider', 'in_consultation', 'admitted'] as $other) {
        expect(encounterIdsForStage($other))->not->toContain($encounter->id);
    }
});

it('places every visit in exactly one pile', function (): void {
    $patient = clinicianStagePatient();
    clinicianStageEncounter($patient->id, ['appointment_id' => clinicianStageAppointment($patient->id, 'waiting_provider', now()->toDateTimeString())->id]);
    clinicianStageEncounter($patient->id, ['appointment_id' => clinicianStageAppointment($patient->id, 'in_consultation', now()->toDateTimeString())->id]);
    clinicianStageEncounter($patient->id, ['appointment_id' => clinicianStageAppointment($patient->id, 'completed', now()->toDateTimeString())->id, 'status' => 'closed']);
    clinicianStageEncounter($patient->id, ['appointment_id' => null]);

    $seen = [];
    foreach (ClinicianQueueStage::values() as $stage) {
        foreach (encounterIdsForStage($stage) as $id) {
            $seen[] = $id;
        }
    }

    expect($seen)->toHaveCount(count(array_unique($seen)));
    expect(array_unique($seen))->toHaveCount(4);
});

it('counts the whole queue, not the page being shown', function (): void {
    $patient = clinicianStagePatient();
    // More than one page of the old default (15) in a single pile.
    for ($i = 0; $i < 22; $i++) {
        clinicianStageEncounter($patient->id, [
            'appointment_id' => clinicianStageAppointment($patient->id, 'waiting_provider', now()->toDateTimeString())->id,
        ]);
    }

    $counts = app(ListClinicianQueueStageCountsUseCase::class)->execute([]);

    expect($counts['waiting_provider'])->toBe(22);
    expect($counts['in_consultation'])->toBe(0);
});

it('pages within one pile instead of truncating everything', function (): void {
    $patient = clinicianStagePatient();
    for ($i = 0; $i < 22; $i++) {
        clinicianStageEncounter($patient->id, [
            'appointment_id' => clinicianStageAppointment($patient->id, 'waiting_provider', now()->toDateTimeString())->id,
        ]);
    }

    $firstPage = app(ListEncountersUseCase::class)->execute([
        'queueStage' => 'waiting_provider',
        'perPage' => 20,
        'page' => 1,
    ]);
    $secondPage = app(ListEncountersUseCase::class)->execute([
        'queueStage' => 'waiting_provider',
        'perPage' => 20,
        'page' => 2,
    ]);

    expect($firstPage['data'])->toHaveCount(20);
    expect($secondPage['data'])->toHaveCount(2);
});
