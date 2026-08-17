<?php

use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\PatientFlow\Infrastructure\Models\PatientFlowEventModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Regression coverage for finding 02 of the 2026-08-16 patient-flow audit.
 *
 * PatientVitalSetController used to load an explicitly-supplied appointmentId
 * with no status filter and force it to WAITING_PROVIDER via a raw
 * $appointment->update(). Recording vitals for a patient who was already
 * `in_consultation` therefore pulled them straight back out of the doctor's
 * room — and because the write bypassed UpdateAppointmentStatusUseCase, it
 * left no row in appointment_audit_logs, so nothing anywhere recorded that it
 * had happened.
 */
function consultationVitalsActor(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('patient.vitals.record');
    $user->givePermissionTo('patients.read');
    $user->givePermissionTo('appointments.record-triage');

    return $user;
}

function consultationVitalsPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Baraka',
        'last_name' => 'Kimaro',
        'gender' => 'male',
        'date_of_birth' => '1985-03-11',
        'phone' => '+255712000555',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function consultationVitalsAppointment(string $patientId, string $status): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Visit',
        'status' => $status,
    ]);
}

it('does not pull a patient out of an active consultation when vitals are recorded', function (): void {
    $user = consultationVitalsActor();
    $patient = consultationVitalsPatient();
    $appointment = consultationVitalsAppointment($patient->id, 'in_consultation');

    $this->actingAs($user)->postJson('/api/v1/nursing/vitals', [
        'patientId' => $patient->id,
        'appointmentId' => $appointment->id,
        'temperatureC' => 37.1,
        'heartRateBpm' => 78,
    ])->assertStatus(201);

    expect($appointment->fresh()->status)->toBe('in_consultation');
});

it('still records the vitals themselves for a patient who is with a doctor', function (): void {
    $user = consultationVitalsActor();
    $patient = consultationVitalsPatient();
    $appointment = consultationVitalsAppointment($patient->id, 'in_consultation');

    $this->actingAs($user)->postJson('/api/v1/nursing/vitals', [
        'patientId' => $patient->id,
        'appointmentId' => $appointment->id,
        'temperatureC' => 37.1,
    ])->assertStatus(201);

    // The clinical observation is never the thing that gets dropped — only the
    // unwanted status change is.
    $saved = App\Modules\PatientVitals\Infrastructure\Models\PatientVitalSetModel::query()->first();
    expect($saved)->not->toBeNull()
        ->and((float) $saved->temperature_c)->toBe(37.1);
});

it('records the triage handoff in the flow log when vitals do advance a waiting visit', function (): void {
    $user = consultationVitalsActor();
    $patient = consultationVitalsPatient();
    $appointment = consultationVitalsAppointment($patient->id, 'waiting_triage');

    $this->actingAs($user)->postJson('/api/v1/nursing/vitals', [
        'patientId' => $patient->id,
        'appointmentId' => $appointment->id,
        'temperatureC' => 36.8,
        'heartRateBpm' => 72,
    ])->assertStatus(201);

    expect($appointment->fresh()->status)->toBe('waiting_provider');

    $vitalsEvent = PatientFlowEventModel::query()
        ->where('appointment_id', $appointment->id)
        ->where('source', 'nursing.vitals_recorded')
        ->first();

    expect($vitalsEvent)->not->toBeNull()
        ->and($vitalsEvent->to_step)->toBe('waiting_triage')
        ->and($vitalsEvent->actor_user_id)->toBe($user->id);

    $handoffEvent = PatientFlowEventModel::query()
        ->where('appointment_id', $appointment->id)
        ->where('source', 'triage.handoff_recorded')
        ->first();

    // The transition is now written down, with the code path that caused it —
    // previously it happened with no audit row and no flow record at all.
    expect($handoffEvent)->not->toBeNull()
        ->and($handoffEvent->to_step)->toBe('waiting_clinician')
        ->and($handoffEvent->source)->toBe('triage.handoff_recorded')
        ->and($handoffEvent->actor_user_id)->toBe($user->id);
});

it('advances a walk-in with no department once its vitals are recorded', function (): void {
    // The regression this exists to prevent (2026-08-16). Routing vitals through
    // RecordAppointmentTriageUseCase brought back the transition guard and the
    // audit row, but that use case demands a department or a named provider —
    // and RegisterWalkInAndCheckInUseCase deliberately creates ordinary walk-ins
    // with neither. Every walk-in therefore had its vitals saved and then sat in
    // waiting_triage forever: the queue badge never moved, and the nursing
    // header kept asking for vitals that were already on file.
    $user = consultationVitalsActor();
    $patient = consultationVitalsPatient();

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        // Exactly what a walk-in check-in produces.
        'department' => null,
        'clinician_user_id' => null,
        'scheduled_at' => now()->subMinutes(20)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Walk-in',
        'status' => 'waiting_triage',
    ]);

    $this->actingAs($user)->postJson('/api/v1/nursing/vitals', [
        'patientId' => $patient->id,
        'appointmentId' => $appointment->id,
        'temperatureC' => 36.9,
        'heartRateBpm' => 74,
    ])->assertStatus(201);

    expect($appointment->fresh()->status)->toBe('waiting_provider')
        ->and($appointment->fresh()->triaged_at)->not->toBeNull();
});

it('still records the triage handoff in the flow log for an unrouted walk-in', function (): void {
    $user = consultationVitalsActor();
    $patient = consultationVitalsPatient();

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => null,
        'clinician_user_id' => null,
        'scheduled_at' => now()->subMinutes(20)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Walk-in',
        'status' => 'waiting_triage',
    ]);

    $this->actingAs($user)->postJson('/api/v1/nursing/vitals', [
        'patientId' => $patient->id,
        'appointmentId' => $appointment->id,
        'temperatureC' => 36.9,
    ])->assertStatus(201);

    // Relaxing the routing requirement must not cost the governance the guarded
    // path was adopted for in the first place.
    $event = PatientFlowEventModel::query()
        ->where('appointment_id', $appointment->id)
        ->where('source', 'triage.handoff_recorded')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->to_step)->toBe('waiting_clinician')
        ->and($event->source)->toBe('triage.handoff_recorded');
});


/**
 * A routable clinical department. Built here rather than relied on from seed
 * data, so this asserts the routing contract itself and not the seeder.
 */
function makeRoutableDepartment(string $code = 'OPD', string $name = 'Outpatient Department (OPD)'): object
{
    $id = (string) Str::uuid();

    DB::table('departments')->insert([
        'id' => $id,
        'code' => $code,
        'name' => $name,
        'service_type' => 'Clinical',
        'status' => 'active',
        'is_patient_facing' => true,
        'is_appointmentable' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('departments')->where('id', $id)->first();
}

it('routes an unrouted walk-in to the department the nurse picks at triage', function (): void {
    // The gap this closes (2026-08-16): walk-in registration sets department to
    // null by design, and nothing downstream ever asked for one — the routing
    // endpoint existed but no UI called it. Patients reached the provider queue
    // belonging to no clinic, invisible to every department-filtered board and
    // unattributable for department stock consumption.
    $user = consultationVitalsActor();
    $patient = consultationVitalsPatient();

    $department = makeRoutableDepartment();

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => null,
        'department_id' => null,
        'clinician_user_id' => null,
        'scheduled_at' => now()->subMinutes(15)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Walk-in',
        'status' => 'waiting_triage',
    ]);

    $this->actingAs($user)->postJson('/api/v1/nursing/vitals', [
        'patientId' => $patient->id,
        'appointmentId' => $appointment->id,
        'departmentId' => $department->id,
        'temperatureC' => 36.7,
    ])->assertStatus(201);

    $fresh = $appointment->fresh();

    expect($fresh->status)->toBe('waiting_provider')
        ->and($fresh->department_id)->toBe($department->id)
        // The label is written from the same row, so id and string can never
        // disagree — the drift the free-text column allowed.
        ->and($fresh->department)->toBe($department->name);
});

it('refuses to route a visit to a department that is not active', function (): void {
    $user = consultationVitalsActor();
    $patient = consultationVitalsPatient();

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => null,
        'clinician_user_id' => null,
        'scheduled_at' => now()->subMinutes(15)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Walk-in',
        'status' => 'waiting_triage',
    ]);

    $this->actingAs($user)->postJson('/api/v1/nursing/vitals', [
        'patientId' => $patient->id,
        'appointmentId' => $appointment->id,
        // A well-formed uuid that is not a department at all.
        'departmentId' => '01a00000-0000-7000-8000-000000000000',
        'temperatureC' => 36.7,
    ])->assertStatus(201);

    // Vitals are never lost to a routing problem, and the bad target is refused
    // rather than written.
    expect($appointment->fresh()->department_id)->toBeNull()
        ->and($appointment->fresh()->status)->toBe('waiting_triage');
});
