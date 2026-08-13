<?php

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\EmergencyTriage\Infrastructure\Models\EmergencyTriageCaseModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coverage for Phase 4 of reports/patient-arrival-checkin-modernization-plan.md
 * (decided scope, plan §5): GET /reception/queue backed by a live query over
 * AppointmentModel + ArrivalEventModel, ordered emergency > scheduled > walk-in,
 * oldest-wait-first within each tier. Deliberately not a separately-persisted
 * table (see GetReceptionQueueUseCase's docblock) — no synchronization to
 * verify, just the read itself.
 */
function queuePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTQ'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Queue', 'last_name' => 'Fixture', 'gender' => 'male',
        'date_of_birth' => '1985-05-05', 'phone' => '+255700000018', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

/**
 * `appointment.check-in`, not `appointments.update-status` — see
 * ReceptionCheckInApiTest.php's receptionUser() docblock (bug found
 * & fixed 2026-08-11, same root cause across all reception check-in
 * test files).
 */
function queueUser(): User
{
    $user = User::factory()->create();
    foreach (['appointments.read', 'appointments.create', 'appointment.check-in'] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function checkInViaApi(User $user, string $patientId, string $arrivalMode, ?string $checkedInMinutesAgo = null): string
{
    if ($arrivalMode === 'scheduled_checkin') {
        $appointment = AppointmentModel::query()->create([
            'appointment_number' => 'APTQ'.strtoupper(Str::random(8)),
            'patient_id' => $patientId,
            'department' => 'Outpatient',
            'scheduled_at' => now()->addHour(),
            'duration_minutes' => 30,
            'reason' => 'Consultation',
            'status' => 'scheduled',
        ]);

        test()->actingAs($user)
            ->patchJson('/api/v1/appointments/'.$appointment->id.'/check-in', [])
            ->assertOk();

        $appointmentId = (string) $appointment->id;
    } else {
        $response = test()->actingAs($user)
            ->postJson('/api/v1/reception/walk-ins', [
                'patientId' => $patientId,
                'arrivalMode' => $arrivalMode,
            ])
            ->assertCreated();

        $appointmentId = (string) $response->json('data.id');
    }

    if ($checkedInMinutesAgo !== null) {
        AppointmentModel::query()->where('id', $appointmentId)->update([
            'checked_in_at' => now()->subMinutes((int) $checkedInMinutesAgo),
        ]);
    }

    return $appointmentId;
}

it('orders the triage queue by arrival-mode tier then oldest wait first', function (): void {
    // Mode C (config/reception_automation.php, enabled by default) auto-creates
    // a skeleton EmergencyTriageCase on every emergency check-in. This used
    // to run with Mode C force-disabled, because GetReceptionQueueUseCase::
    // baseQuery() used to whereNotExists() out any appointment with a
    // matching emergency_triage_cases row — with Mode C left on, the
    // emergency fixture below would have vanished from this queue entirely
    // instead of sorting first. That exclusion was itself the reported bug
    // (an emergency-registered patient never appeared in any queue — no
    // frontend anywhere ever consumed the emergency_triage_cases listing
    // it was deferring to) and is gone now (2026-08-12) — this test
    // deliberately runs with Mode C at its real default (enabled) to prove
    // the fix in the realistic configuration, not by working around it.
    $user = queueUser();

    // Registered in an order that would be wrong under pure FIFO, to prove
    // tiering actually reorders them: walk-in first (5 min wait), then
    // scheduled (3 min wait), then emergency (1 min wait, i.e. most recent).
    $walkInId = checkInViaApi($user, queuePatient()->id, 'walk_in', '5');
    $scheduledId = checkInViaApi($user, queuePatient()->id, 'scheduled_checkin', '3');
    $emergencyId = checkInViaApi($user, queuePatient()->id, 'emergency', '1');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=waiting_triage')
        ->assertOk();

    $orderedIds = collect($response->json('data'))->pluck('appointmentId')->all();

    expect($orderedIds)->toBe([$emergencyId, $scheduledId, $walkInId]);
});

it('includes the patient name and number, not just the id, in each queue entry', function (): void {
    $user = queueUser();
    $patient = queuePatient();
    $appointmentId = checkInViaApi($user, $patient->id, 'walk_in');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=waiting_triage')
        ->assertOk();

    $entry = collect($response->json('data'))->firstWhere('appointmentId', $appointmentId);

    expect($entry['patientName'])->toBe('Queue Fixture');
    expect($entry['patientNumber'])->toBe($patient->patient_number);
});

it('orders same-tier entries oldest-wait-first', function (): void {
    $user = queueUser();

    $newer = checkInViaApi($user, queuePatient()->id, 'walk_in', '2');
    $older = checkInViaApi($user, queuePatient()->id, 'walk_in', '10');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=waiting_triage')
        ->assertOk();

    $orderedIds = collect($response->json('data'))->pluck('appointmentId')->all();

    expect($orderedIds)->toBe([$older, $newer]);
});

it('rejects a queue request for an unsupported stage', function (): void {
    $user = queueUser();

    $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=completed')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['stage']);
});

it('returns an empty queue when nothing is waiting', function (): void {
    $user = queueUser();

    $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=waiting_provider')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('forbids queue access without appointments.read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=waiting_triage')
        ->assertForbidden();
});

it('accepts the in_consultation stage and exposes consultation ownership and status on each entry', function (): void {
    $user = queueUser();
    $patient = queuePatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTQ'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'in_consultation',
        'checked_in_at' => now()->subMinutes(30),
        'triaged_at' => now()->subMinutes(20),
        'consultation_started_at' => now()->subMinutes(5),
        'consultation_owner_user_id' => $user->id,
        'consultation_owner_assigned_at' => now()->subMinutes(5),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=in_consultation')
        ->assertOk();

    $entry = collect($response->json('data'))->firstWhere('appointmentId', (string) $appointment->id);

    expect($entry['status'])->toBe('in_consultation');
    expect($entry['consultationOwnerUserId'])->toBe($user->id);
    expect($entry['consultationStartedAt'])->not->toBeNull();
    expect($entry['hasSignedConsultationNote'])->toBeFalse();
});

it('flags hasSignedConsultationNote only when a finalized consultation note exists, not a draft', function (): void {
    $user = queueUser();

    $signedPatient = queuePatient();
    $signedAppointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTQ'.strtoupper(Str::random(8)),
        'patient_id' => $signedPatient->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(10),
        'consultation_owner_user_id' => $user->id,
        'consultation_owner_assigned_at' => now()->subMinutes(10),
    ]);
    MedicalRecordModel::query()->create([
        'record_number' => 'MR'.strtoupper(Str::random(8)),
        'patient_id' => $signedPatient->id,
        'appointment_id' => $signedAppointment->id,
        'author_user_id' => $user->id,
        'encounter_at' => now()->subMinutes(10),
        'record_type' => 'consultation_note',
        'status' => 'finalized',
        'signed_by_user_id' => $user->id,
        'signed_at' => now()->subMinutes(2),
    ]);

    $draftPatient = queuePatient();
    $draftAppointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTQ'.strtoupper(Str::random(8)),
        'patient_id' => $draftPatient->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(10),
        'consultation_owner_user_id' => $user->id,
        'consultation_owner_assigned_at' => now()->subMinutes(10),
    ]);
    MedicalRecordModel::query()->create([
        'record_number' => 'MR'.strtoupper(Str::random(8)),
        'patient_id' => $draftPatient->id,
        'appointment_id' => $draftAppointment->id,
        'author_user_id' => $user->id,
        'encounter_at' => now()->subMinutes(10),
        'record_type' => 'consultation_note',
        'status' => 'draft',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=in_consultation')
        ->assertOk();

    $entries = collect($response->json('data'));

    expect($entries->firstWhere('appointmentId', (string) $signedAppointment->id)['hasSignedConsultationNote'])->toBeTrue();
    expect($entries->firstWhere('appointmentId', (string) $draftAppointment->id)['hasSignedConsultationNote'])->toBeFalse();
});

it('exposes consultationStep on in_consultation entries, reusing GetActiveVisitJourneyUseCase\'s own diagnostic-step derivation', function (): void {
    $user = queueUser();

    $inLabPatient = queuePatient();
    $inLabAppointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTQ'.strtoupper(Str::random(8)),
        'patient_id' => $inLabPatient->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(10),
        'consultation_owner_user_id' => $user->id,
        'consultation_owner_assigned_at' => now()->subMinutes(10),
    ]);
    \App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.strtoupper(Str::random(8)),
        'patient_id' => $inLabPatient->id,
        'appointment_id' => $inLabAppointment->id,
        'ordered_at' => now(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'status' => 'collected',
    ]);

    $withClinicianPatient = queuePatient();
    $withClinicianAppointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTQ'.strtoupper(Str::random(8)),
        'patient_id' => $withClinicianPatient->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(5),
        'consultation_owner_user_id' => $user->id,
        'consultation_owner_assigned_at' => now()->subMinutes(5),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=in_consultation')
        ->assertOk();

    $entries = collect($response->json('data'));

    expect($entries->firstWhere('appointmentId', (string) $inLabAppointment->id)['consultationStep'])->toBe('in_lab');
    expect($entries->firstWhere('appointmentId', (string) $withClinicianAppointment->id)['consultationStep'])->toBe('with_clinician');
});

it('leaves consultationStep null for stages other than in_consultation', function (): void {
    $user = queueUser();
    checkInViaApi($user, queuePatient()->id, 'walk_in');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=waiting_triage')
        ->assertOk();

    $entry = collect($response->json('data'))->first();

    expect($entry['consultationStep'])->toBeNull();
});

it('filters the queue by patient name, MRN, or appointment number', function (): void {
    $user = queueUser();
    $target = PatientModel::query()->create([
        'patient_number' => 'PTQ'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Zawadi', 'last_name' => 'Mrema', 'gender' => 'female',
        'date_of_birth' => '1990-01-01', 'phone' => '+255700000099', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
    $targetAppointmentId = checkInViaApi($user, $target->id, 'walk_in');
    checkInViaApi($user, queuePatient()->id, 'walk_in');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=waiting_triage&q=Zawadi')
        ->assertOk();

    $orderedIds = collect($response->json('data'))->pluck('appointmentId')->all();
    expect($orderedIds)->toBe([$targetAppointmentId]);
});

/**
 * Postgres's LIKE is case-sensitive (unlike SQLite, which this test suite
 * runs on) — a plain where('first_name', 'like', ...) silently missed
 * mixed-case matches in production. This is very likely why the reception
 * queue search "never worked" in practice: a receptionist typing a
 * patient's name rarely matches the exact stored casing. Fixed via
 * GetReceptionQueueUseCase::baseQuery() (LOWER() on both sides). Can't
 * reproduce the case-sensitivity bug itself on SQLite, but this documents
 * and guards the intended case-insensitive behavior going forward.
 */
it('filters the queue by patient name regardless of case', function (): void {
    $user = queueUser();
    $target = PatientModel::query()->create([
        'patient_number' => 'PTQ'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Zawadi', 'last_name' => 'Mrema', 'gender' => 'female',
        'date_of_birth' => '1990-01-01', 'phone' => '+255700000098', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
    $targetAppointmentId = checkInViaApi($user, $target->id, 'walk_in');
    checkInViaApi($user, queuePatient()->id, 'walk_in');

    foreach (['zawadi', 'ZAWADI', 'ZaWaDi'] as $term) {
        $response = $this->actingAs($user)
            ->getJson('/api/v1/reception/queue?stage=waiting_triage&q='.$term)
            ->assertOk();

        $orderedIds = collect($response->json('data'))->pluck('appointmentId')->all();
        expect($orderedIds)->toBe([$targetAppointmentId]);
    }
});

it('filters the queue by department', function (): void {
    $user = queueUser();
    $patient = queuePatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTQ'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'department' => 'Dental',
        'scheduled_at' => now()->addHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'waiting_triage',
        'checked_in_at' => now()->subMinutes(5),
    ]);
    checkInViaApi($user, queuePatient()->id, 'walk_in');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=waiting_triage&department=Dental')
        ->assertOk();

    $orderedIds = collect($response->json('data'))->pluck('appointmentId')->all();
    expect($orderedIds)->toBe([(string) $appointment->id]);
});

it('paginates the queue and returns accurate meta', function (): void {
    $user = queueUser();
    for ($i = 0; $i < 3; $i++) {
        checkInViaApi($user, queuePatient()->id, 'walk_in');
    }

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=waiting_triage&perPage=2&page=1')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('meta.total'))->toBe(3);
    expect($response->json('meta.lastPage'))->toBe(2);

    $secondPage = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=waiting_triage&perPage=2&page=2')
        ->assertOk();

    expect($secondPage->json('data'))->toHaveCount(1);
});

it('returns status counts across all three reception stages', function (): void {
    $user = queueUser();
    checkInViaApi($user, queuePatient()->id, 'walk_in');
    checkInViaApi($user, queuePatient()->id, 'walk_in');
    checkInViaApi($user, queuePatient()->id, 'scheduled_checkin');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue/status-counts')
        ->assertOk();

    expect($response->json('data.waiting_triage'))->toBe(3);
    expect($response->json('data.waiting_provider'))->toBe(0);
    expect($response->json('data.total'))->toBe(3);
});

it('forbids status counts without appointments.read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/reception/queue/status-counts')
        ->assertForbidden();
});

/**
 * Regression test for the reported bug (2026-08-12): an emergency-
 * registered patient never appeared in the Reception queue. Root cause —
 * GetReceptionQueueUseCase::baseQuery() used to whereNotExists() out any
 * appointment with a matching emergency_triage_cases row (auto-created by
 * Mode C, config/reception_automation.php, enabled by default), on the
 * assumption a separate "/emergency/queue" page would pick it up instead.
 * That page never existed anywhere in the frontend — confirmed via a full
 * grep of resources/ts for any consumer of GET /emergency-triage-cases —
 * so every emergency check-in was silently hidden from the only queue
 * view that exists. This test runs with Mode C at its real default
 * (enabled), asserts the skeleton EmergencyTriageCase really is created
 * (proving the check-in itself worked correctly, matching what was found
 * live for the reported patient), and asserts the appointment still shows
 * up in the queue anyway.
 */
it('shows an emergency-registered patient in the queue even though a skeleton EmergencyTriageCase was auto-created', function (): void {
    $user = queueUser();
    $patient = queuePatient();

    $appointmentId = checkInViaApi($user, $patient->id, 'emergency');

    expect(EmergencyTriageCaseModel::query()->where('appointment_id', $appointmentId)->exists())
        ->toBeTrue();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=waiting_triage')
        ->assertOk();

    $entry = collect($response->json('data'))->firstWhere('appointmentId', $appointmentId);

    expect($entry)->not->toBeNull();
    expect($entry['patientId'])->toBe($patient->id);
    expect($entry['arrivalMode'])->toBe('emergency');
    expect($entry['tier'])->toBe(0);
});
