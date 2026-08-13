<?php

use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coverage for Volume 2.1 §9 (Appointment Scheduling) reception-scoped
 * routes added 2026-08-10: GET reception/appointments (patientName/
 * patientNumber enrichment on top of the shared ListAppointmentsUseCase +
 * AppointmentResponseTransformer), GET reception/appointments/
 * department-options and GET reception/clinicians (both thin passthroughs
 * to existing controllers), and that reception/appointments POST still
 * surfaces the shared conflict-exception shape (clinicianScheduleConflict)
 * the create-appointment form depends on — already covered end-to-end
 * against the generic route by AppointmentClinicianConflictTest; this just
 * proves the reception-scoped route delegates to the same code path.
 */
function schedulingUser(): User
{
    $user = User::factory()->create();
    foreach (['appointments.read', 'appointments.create', 'staff.clinical-directory.read'] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function schedulingPatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PTSCH'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Amina',
        'last_name' => 'Scheduler',
        'gender' => 'female',
        'date_of_birth' => '1990-05-05',
        'phone' => '+255700000501',
        'country_code' => 'TZ',
        'status' => 'active',
    ], $overrides));
}

it('enriches the reception appointments list with patientName and patientNumber', function (): void {
    $actor = schedulingUser();
    $patient = schedulingPatient();
    AppointmentModel::query()->create([
        'appointment_number' => 'APSCH'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay(),
        'duration_minutes' => 30,
        'reason' => 'Follow-up',
        'status' => 'scheduled',
    ]);

    $response = $this->actingAs($actor)
        ->getJson('/api/v1/reception/appointments')
        ->assertOk();

    expect($response->json('data.0.patientName'))->toBe('Amina Scheduler');
    expect($response->json('data.0.patientNumber'))->toBe($patient->patient_number);
    // Still the shared transformer's shape underneath — proves no logic was
    // duplicated, only the two extra fields were added on top.
    expect($response->json('data.0.consultationType'))->not->toBeNull();
});

it('serves department options on the reception-scoped route', function (): void {
    $actor = schedulingUser();
    DepartmentModel::query()->create([
        'code' => 'OPD',
        'name' => 'Outpatient',
        'service_type' => 'general',
        'is_patient_facing' => true,
        'is_appointmentable' => true,
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/reception/appointments/department-options')
        ->assertOk()
        ->assertJsonFragment(['value' => 'Outpatient']);
});

it('serves the active clinical staff directory on the reception-scoped clinicians route', function (): void {
    $actor = schedulingUser();
    $department = DepartmentModel::query()->create([
        'code' => 'MED',
        'name' => 'Medicine',
        'service_type' => 'general',
        'is_patient_facing' => true,
        'is_appointmentable' => true,
        'status' => 'active',
    ]);
    $clinician = User::factory()->create(['name' => 'Dr. Juma Clinician']);
    StaffProfileModel::query()->create([
        'user_id' => $clinician->id,
        'employee_number' => 'STF-SCH-001',
        'department_id' => $department->id,
        'department' => 'Medicine',
        'job_title' => 'Medical Officer',
        'professional_license_number' => 'MO-SCH-001',
        'license_type' => 'Medical Officer',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/reception/clinicians')
        ->assertOk()
        ->assertJsonFragment(['userName' => 'Dr. Juma Clinician']);
});

it('forbids the reception clinicians route without staff.clinical-directory.read', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('appointments.read');

    $this->actingAs($actor)
        ->getJson('/api/v1/reception/clinicians')
        ->assertForbidden();
});

it('surfaces the shared clinician-conflict shape through the reception-scoped create route', function (): void {
    $actor = schedulingUser();
    $clinician = User::factory()->create();
    $patientOne = schedulingPatient(['phone' => '+255700000502']);
    $patientTwo = schedulingPatient(['phone' => '+255700000503']);
    $start = now()->addDay()->setTime(9, 0);

    $this->actingAs($actor)
        ->postJson('/api/v1/reception/appointments', [
            'patientId' => $patientOne->id,
            'scheduledAt' => $start->toDateTimeString(),
            'durationMinutes' => 30,
            'clinicianUserId' => $clinician->id,
        ])
        ->assertCreated();

    $response = $this->actingAs($actor)
        ->postJson('/api/v1/reception/appointments', [
            'patientId' => $patientTwo->id,
            'scheduledAt' => $start->copy()->addMinutes(15)->toDateTimeString(),
            'durationMinutes' => 30,
            'clinicianUserId' => $clinician->id,
        ])
        ->assertStatus(422);

    expect($response->json('context.clinicianScheduleConflict'))->not->toBeNull();
});
