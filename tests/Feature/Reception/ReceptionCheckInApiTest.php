<?php

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coverage for Phase 1 of reports/patient-arrival-checkin-modernization-plan.md:
 * PATCH appointments/{id}/check-in (arrival event + status change for a
 * pre-existing scheduled appointment) and POST reception/walk-ins (atomic
 * appointment-create + check-in, replacing the two-sequential-call pattern
 * named in reports/patient-arrival-checkin-audit.md §4). Neither
 * CreateAppointmentUseCase nor UpdateAppointmentStatusUseCase is modified —
 * this only proves the new coordination layer calls them correctly and adds
 * the ArrivalEvent audit trail atomically.
 */
function receptionPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTRCP'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Reception', 'last_name' => 'Fixture', 'gender' => 'female',
        'date_of_birth' => '1991-11-11', 'phone' => '+255700000017', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

/**
 * `appointment.check-in`, not `appointments.update-status` (bug found
 * & fixed 2026-08-11) — the check-in/walk-in routes this file exercises
 * check `can:appointment.check-in` (routes/api.php), the granular
 * permission `appointments.update-status` was split into per the same
 * least-privilege refactor as patient.demographics.update (see
 * PatientApiTest.php's grantPatientUpdatePermission() docblock). This
 * user was missing the permission the routes actually require.
 */
function receptionUser(): User
{
    $user = User::factory()->create();
    foreach (['appointments.read', 'appointments.create', 'appointment.check-in'] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function receptionScheduledAppointment(string $patientId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APTRCP'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'scheduled',
    ]);
}

it('checks in a scheduled appointment and records an arrival event', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();
    $appointment = receptionScheduledAppointment($patient->id);

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/check-in', [
            'verificationNotes' => 'ID verified at desk',
        ])
        ->assertOk();

    $response->assertJsonPath('data.status', 'waiting_triage');

    expect(AppointmentModel::query()->find($appointment->id))
        ->status->toBe('waiting_triage')
        ->checked_in_at->not->toBeNull();

    $arrivalEvent = ArrivalEventModel::query()->where('appointment_id', $appointment->id)->first();
    expect($arrivalEvent)->not->toBeNull();
    expect($arrivalEvent->arrival_mode)->toBe('scheduled_checkin');
    expect($arrivalEvent->recorded_by_user_id)->toBe($user->id);
    expect($arrivalEvent->verification_notes)->toBe('ID verified at desk');
});

it('rejects check-in for an appointment that is already past the waiting_triage stage', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();
    $appointment = receptionScheduledAppointment($patient->id);
    $appointment->forceFill(['status' => 'completed'])->save();

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/check-in', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'APPOINTMENT_STATUS_TRANSITION_INVALID');

    expect(ArrivalEventModel::query()->where('appointment_id', $appointment->id)->count())->toBe(0);
});

it('registers a walk-in and checks it in atomically with one call', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'walk_in',
            'reason' => 'OPD walk-in from front desk',
        ])
        ->assertCreated();

    $response->assertJsonPath('data.status', 'waiting_triage');
    $appointmentId = $response->json('data.id');

    expect(AppointmentModel::query()->find($appointmentId))
        ->status->toBe('waiting_triage')
        ->appointment_type->toBe('walk_in');

    $arrivalEvent = ArrivalEventModel::query()->where('appointment_id', $appointmentId)->first();
    expect($arrivalEvent)->not->toBeNull();
    expect($arrivalEvent->arrival_mode)->toBe('walk_in');
});

it('registers an emergency walk-in with the emergency arrival mode', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'emergency',
        ])
        ->assertCreated();

    $appointmentId = $response->json('data.id');
    $arrivalEvent = ArrivalEventModel::query()->where('appointment_id', $appointmentId)->first();

    expect($arrivalEvent->arrival_mode)->toBe('emergency');
});

it('rejects walk-in registration without both create and update-status permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('appointments.create');
    $patient = receptionPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'walk_in',
        ])
        ->assertForbidden();
});

it('rejects an invalid arrival mode for walk-in registration', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'scheduled_checkin',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['arrivalMode']);
});

/**
 * Duplicate check-in prevention (2026-08-12, bug fix): the header
 * Check-In action previously had no guard at all — RegisterWalkInAndCheckInUseCase
 * unconditionally created a brand-new Appointment every call, so clicking
 * it twice on the same patient created two independent, unresolved
 * visits. RegisterWalkInAndCheckInUseCase now checks
 * AppointmentRepositoryInterface::findActiveForPatient() (patient-row-
 * locked, race-safe) before creating anything and rejects via the
 * existing ActiveAppointmentConflictException/AppointmentConflictMessageFormatter
 * plumbing ReceptionController::registerWalkIn() already had wired for
 * exactly this exception.
 */
it('rejects a second walk-in registration while the patient already has an active visit', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();

    $first = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'walk_in',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'emergency',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('data.activeAppointmentConflict.id', $first);

    expect(AppointmentModel::query()->where('patient_id', $patient->id)->count())->toBe(1);
    expect(EncounterModel::query()->where('patient_id', $patient->id)->count())->toBe(1);
});

it('rejects a second walk-in registration even when the existing active visit started on a previous day', function (): void {
    // Regression test for the reported bug: the guard this replaced
    // (CreateAppointmentUseCase::assertNoActiveSameDayConflict(), still
    // used for the unrelated future-scheduling conflict check) was scoped
    // to whereDate('scheduled_at', today) — a visit that started
    // yesterday and never resolved was invisible to it, silently letting
    // a second check-in through. findActiveForPatient() is not
    // date-scoped.
    $user = receptionUser();
    $patient = receptionPatient();

    $appointmentId = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'walk_in',
        ])
        ->assertCreated()
        ->json('data.id');

    AppointmentModel::query()->where('id', $appointmentId)->update([
        'scheduled_at' => now()->subDay(),
        'checked_in_at' => now()->subDay(),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'walk_in',
        ])
        ->assertStatus(422)
        ->assertJsonPath('data.activeAppointmentConflict.id', $appointmentId);

    expect(AppointmentModel::query()->where('patient_id', $patient->id)->count())->toBe(1);
});

it('allows a new walk-in registration after the previous visit is completed, without touching the old visit', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();

    $firstAppointmentId = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'walk_in',
        ])
        ->assertCreated()
        ->json('data.id');

    AppointmentModel::query()->where('id', $firstAppointmentId)->update(['status' => 'completed']);

    $secondAppointmentId = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'walk_in',
        ])
        ->assertCreated()
        ->json('data.id');

    expect($secondAppointmentId)->not->toBe($firstAppointmentId);
    expect(AppointmentModel::query()->where('patient_id', $patient->id)->count())->toBe(2);
    // Visit history preserved, not overwritten: the first appointment
    // (and its own encounter) still exists exactly as it was left.
    expect(AppointmentModel::query()->find($firstAppointmentId)->status)->toBe('completed');
    expect(EncounterModel::query()->where('patient_id', $patient->id)->count())->toBe(2);
});

/**
 * Phase 3 (plan §5, decided): check-in also opens the visit's Encounter —
 * one Encounter spans the whole visit rather than a separate administrative
 * record. This must not grant reception any clinical capability: they still
 * lack medical.records.create and cannot reach the note-creation endpoint.
 */
it('opens the encounter for the appointment at check-in', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();
    $appointment = receptionScheduledAppointment($patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/check-in', [])
        ->assertOk();

    $encounter = EncounterModel::query()->where('appointment_id', $appointment->id)->first();
    expect($encounter)->not->toBeNull();
    expect($encounter->patient_id)->toBe($patient->id);
    expect($encounter->status)->toBe('opened');
});

it('resolves the same encounter on repeated check-in-adjacent calls instead of duplicating it', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();
    $appointment = receptionScheduledAppointment($patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/check-in', [])
        ->assertOk();

    $firstEncounterId = EncounterModel::query()->where('appointment_id', $appointment->id)->value('id');

    // Same-status re-check-in is idempotent per AppointmentStatus::canTransitionTo();
    // this proves the encounter side is equally idempotent, not re-created.
    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/check-in', [])
        ->assertOk();

    expect(EncounterModel::query()->where('appointment_id', $appointment->id)->count())->toBe(1);
    expect(EncounterModel::query()->where('appointment_id', $appointment->id)->value('id'))->toBe($firstEncounterId);
});

it('opens an emergency-typed encounter for an emergency walk-in', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'emergency',
        ])
        ->assertCreated();

    $appointmentId = $response->json('data.id');
    $encounter = EncounterModel::query()->where('appointment_id', $appointmentId)->first();

    expect($encounter)->not->toBeNull();
    expect($encounter->type)->toBe('emergency');
});

it('still forbids a reception-only user from creating a medical record after check-in opens the encounter', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();
    $appointment = receptionScheduledAppointment($patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/check-in', [])
        ->assertOk();

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', [
            'patientId' => $patient->id,
            'appointmentId' => $appointment->id,
            'encounterAt' => now()->toDateTimeString(),
            'recordType' => 'consultation_note',
            'subjective' => 'Should not be reachable by reception.',
        ])
        ->assertForbidden();
});

/**
 * Bug fix coverage (2026-08-11) — see CheckInUseCase/CancelQueueItemUseCase
 * docblocks: check-in and cancel now write to the patient's own audit trail
 * (PatientAuditLogRepositoryInterface), and cancel additionally closes out
 * the visit's Encounter so the Patient Profile's "Latest visit" card stops
 * showing a cancelled visit as permanently "In progress".
 */
it('writes a patient-audit-trail entry for a scheduled check-in', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();
    $appointment = receptionScheduledAppointment($patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/check-in', [])
        ->assertOk();

    $entry = \App\Modules\Patient\Infrastructure\Models\PatientAuditLogModel::query()
        ->where('patient_id', $patient->id)
        ->where('action', 'patient.visit.checked_in')
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry->actor_id)->toBe($user->id);
});

it('cancels the visit encounter when the appointment is cancelled before any clinical work started', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();
    $appointment = receptionScheduledAppointment($patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/check-in', [])
        ->assertOk();

    $encounter = EncounterModel::query()->where('appointment_id', $appointment->id)->first();
    expect($encounter->status)->toBe('opened');

    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/'.$appointment->id.'/cancel', [
            'reason' => 'Patient left before triage',
        ])
        ->assertOk();

    expect($encounter->fresh()->status)->toBe('cancelled');

    $auditEntry = \App\Modules\Patient\Infrastructure\Models\PatientAuditLogModel::query()
        ->where('patient_id', $patient->id)
        ->where('action', 'patient.visit.cancelled')
        ->first();
    expect($auditEntry)->not->toBeNull();
});

it('does not touch the encounter on cancel once clinical work has already started on it', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();
    $appointment = receptionScheduledAppointment($patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/check-in', [])
        ->assertOk();

    $encounter = EncounterModel::query()->where('appointment_id', $appointment->id)->first();
    $encounter->forceFill(['status' => 'in_progress'])->save();

    // in_consultation -> cancelled is a valid transition (AppointmentStatus::
    // allowedForwardTransitions()), so this must still succeed at the
    // appointment level — only the encounter must be left alone.
    $appointment->forceFill(['status' => 'in_consultation'])->save();

    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/'.$appointment->id.'/cancel', [
            'reason' => 'Cancelled mid-consultation',
        ])
        ->assertOk();

    expect($encounter->fresh()->status)->toBe('in_progress');
});

it('auto-resolves the skeleton EmergencyTriageCase when the emergency visit is cancelled, and allows a new check-in afterward', function (): void {
    // Direct user bug report (2026-08-12): cancelling Salome Mgonja's
    // emergency visit still showed "Latest visit: Cancelled" on her
    // profile, but re-check-in failed with "Patient has an active
    // emergency case... Resolve or discharge the emergency visit before
    // scheduling a new appointment." Root cause: CreateSkeletonEmergencyTriageCase
    // auto-creates a WAITING case on emergency check-in, but nothing ever
    // resolved it when the linked appointment was cancelled — see
    // ResolveSkeletonEmergencyTriageCaseOnAppointmentClosure's own
    // docblock for the fix.
    $user = receptionUser();
    $patient = receptionPatient();

    $appointmentId = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'emergency',
        ])
        ->assertCreated()
        ->json('data.id');

    $case = \App\Modules\EmergencyTriage\Infrastructure\Models\EmergencyTriageCaseModel::query()
        ->where('appointment_id', $appointmentId)
        ->first();
    expect($case)->not->toBeNull();
    expect($case->status)->toBe('waiting');

    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/'.$appointmentId.'/cancel', [
            'reason' => 'Patient left before triage',
        ])
        ->assertOk();

    expect(AppointmentModel::query()->find($appointmentId)->status)->toBe('cancelled');
    expect($case->fresh()->status)->toBe('cancelled');

    // The actual reported symptom: re-check-in must now succeed instead of
    // 422ing with "Patient has an active emergency case...".
    $second = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'walk_in',
        ])
        ->assertCreated();

    expect($second->json('data.id'))->not->toBe($appointmentId);
});

it('discharges the skeleton EmergencyTriageCase when the emergency visit is completed', function (): void {
    $user = receptionUser();
    $patient = receptionPatient();

    $appointmentId = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'emergency',
        ])
        ->assertCreated()
        ->json('data.id');

    $case = \App\Modules\EmergencyTriage\Infrastructure\Models\EmergencyTriageCaseModel::query()
        ->where('appointment_id', $appointmentId)
        ->first();

    AppointmentModel::query()->find($appointmentId)->forceFill(['status' => 'completed'])->save();
    event(new \App\Modules\Appointment\Domain\Events\AppointmentStatusChanged(
        appointmentId: $appointmentId,
        patientId: $patient->id,
        oldStatus: 'in_consultation',
        newStatus: 'completed',
        actorId: $user->id,
    ));

    expect($case->fresh()->status)->toBe('discharged');
});

it('does not auto-resolve an EmergencyTriageCase once a clinician has assigned a real triage level', function (): void {
    // Safety guard: once a real clinician has touched the case
    // (triage_level moved off the 'unassigned' skeleton marker), cancelling
    // the linked appointment must NOT silently cancel their in-progress
    // clinical assessment.
    $user = receptionUser();
    $patient = receptionPatient();

    $appointmentId = $this->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'emergency',
        ])
        ->assertCreated()
        ->json('data.id');

    $case = \App\Modules\EmergencyTriage\Infrastructure\Models\EmergencyTriageCaseModel::query()
        ->where('appointment_id', $appointmentId)
        ->first();
    $case->forceFill(['triage_level' => 'yellow'])->save();

    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/'.$appointmentId.'/cancel', [
            'reason' => 'Administrative cancellation',
        ])
        ->assertOk();

    expect($case->fresh()->status)->toBe('waiting');
});
