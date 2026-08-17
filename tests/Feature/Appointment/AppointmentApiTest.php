<?php

use App\Models\User;
use App\Notifications\AppointmentConsultationTakenOverNotification;
use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentAuditLogModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Amina',
        'middle_name' => null,
        'last_name' => 'Moshi',
        'gender' => 'female',
        'date_of_birth' => '1996-04-21',
        'phone' => '+255700000001',
        'email' => null,
        'national_id' => null,
        'country_code' => 'TZ',
        'region' => null,
        'district' => null,
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function appointmentPayload(string $patientId, array $overrides = []): array
{
    return array_merge([
        'patientId' => $patientId,
        'scheduledAt' => now()->addDay()->toDateTimeString(),
        'durationMinutes' => 30,
        'reason' => 'General consultation',
        'notes' => 'Follow-up required',
        'department' => 'Outpatient',
    ], $overrides);
}

function consultationNotePayload(
    string $patientId,
    string $appointmentId,
    array $overrides = [],
): array {
    return array_merge([
        'patientId' => $patientId,
        'appointmentId' => $appointmentId,
        'encounterAt' => now()->toDateTimeString(),
        'recordType' => 'consultation_note',
        'subjective' => 'Patient reports mild pain',
        'objective' => 'Vitals stable',
        'assessment' => 'Recovering well',
        'plan' => 'Continue observation for 24 hours',
        'diagnosisCode' => 'R52',
    ], $overrides);
}

function makeAdmission(string $patientId, array $overrides = []): AdmissionModel
{
    return AdmissionModel::query()->create(array_merge([
        'admission_number' => 'ADM'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Medical Ward',
        'bed' => 'B-12',
        'admitted_at' => now()->subDays(3),
        'discharged_at' => now()->subHours(6),
        'admission_reason' => 'Inpatient treatment',
        'notes' => 'Discharged with follow-up.',
        'status' => 'discharged',
        'status_reason' => 'Clinically improved',
        'discharge_destination' => 'Home',
        'follow_up_plan' => 'Review after one week at clinic.',
    ], $overrides));
}

function grantAppointmentReadPermission(User $user): void
{
    $user->givePermissionTo('appointments.read');
}

function grantAppointmentWorkflowPermissions(User $user): void
{
    foreach ([
        'appointments.read',
        'appointments.create',
        'appointments.update',
        'appointments.update-status',
    ] as $permission) {
        $user->givePermissionTo($permission);
    }
}


function grantAppointmentTriagePermissions(User $user): void
{
    foreach ([
        'appointments.read',
        'appointments.record-triage',
        'emergency.triage.read',
        'emergency.triage.create',
        'emergency.triage.update',
        'emergency.triage.update-status',
    ] as $permission) {
        $user->givePermissionTo($permission);
    }
}


function grantAppointmentClinicianPermissions(User $user): void
{
    foreach ([
        'appointments.read',
        'appointments.start-consultation',
        'appointments.manage-provider-session',
        'medical.records.read',
        'medical.records.create',
    ] as $permission) {
        $user->givePermissionTo($permission);
    }

    if (! StaffProfileModel::query()->where('user_id', $user->id)->exists()) {
        StaffProfileModel::query()->create([
            'user_id' => $user->id,
            'employee_number' => 'EMP'.strtoupper(Str::random(8)),
            'department' => 'Outpatient',
            'job_title' => 'Clinical Officer',
            'professional_license_number' => null,
            'license_type' => null,
            'phone_extension' => null,
            'employment_type' => 'full_time',
            'status' => 'active',
            'status_reason' => null,
        ]);
    }
}

function makeAppointmentUser(): User
{
    $user = User::factory()->create();
    grantAppointmentWorkflowPermissions($user);

    return $user;
}

/**
 * Facility super admin, per User::isFacilitySuperAdminAccess() -> hasUniversalAdminAccess()
 * -> isFacilitySuperAdmin(): requires an active facility_user row with role = 'super_admin'.
 */
function makeFacilitySuperAdminUser(): User
{
    $user = User::factory()->create();
    grantAppointmentWorkflowPermissions($user);

    $tenantId = (string) Str::uuid();
    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'SA'.strtoupper(Str::random(6)),
        'name' => 'Super Admin Test Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $facilityId = (string) Str::uuid();
    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'SA'.strtoupper(Str::random(6)),
        'name' => 'Super Admin Test Facility',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $user->id,
        'role' => 'super_admin',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

it('requires authentication for appointment creation', function (): void {
    $patient = makePatient();

    $this->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertUnauthorized();
});

it('forbids appointment list without read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/appointments')
        ->assertForbidden();
});

it('forbids appointment show without read permission', function (): void {
    $userWithRead = makeAppointmentUser();
    $userWithoutRead = User::factory()->create();
    $patient = makePatient();

    $created = $this->actingAs($userWithRead)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/appointments/'.$created['id'])
        ->assertForbidden();
});
it('forbids appointment creation without create permission', function (): void {
    $user = User::factory()->create();
    grantAppointmentReadPermission($user);
    $patient = makePatient();

    $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertForbidden();
});

it('forbids appointment department options without routing access', function (): void {
    $user = User::factory()->create();
    grantAppointmentReadPermission($user);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/department-options')
        ->assertForbidden();
});

it('lists only patient-facing appointmentable departments when create permission exists', function (): void {
    $user = makeAppointmentUser();

    DepartmentModel::query()->create([
        'code' => 'OPD',
        'name' => 'General OPD',
        'service_type' => 'Clinical',
        'is_patient_facing' => true,
        'is_appointmentable' => true,
        'status' => 'active',
    ]);

    DepartmentModel::query()->create([
        'code' => 'ICT',
        'name' => 'ICT and Systems',
        'service_type' => 'Support',
        'is_patient_facing' => false,
        'is_appointmentable' => false,
        'status' => 'active',
    ]);

    DepartmentModel::query()->create([
        'code' => 'FIN',
        'name' => 'Billing and Finance',
        'service_type' => 'Administrative',
        'is_patient_facing' => true,
        'is_appointmentable' => false,
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/department-options')
        ->assertOk()
        ->assertJsonPath('data.0.value', 'General OPD')
        ->assertJsonMissing(['value' => 'ICT and Systems'])
        ->assertJsonMissing(['value' => 'Billing and Finance']);
});

it('allows triage staff to read appointment department options for routing', function (): void {
    $user = User::factory()->create();
    grantAppointmentTriagePermissions($user);

    DepartmentModel::query()->create([
        'code' => 'OPD',
        'name' => 'General OPD',
        'service_type' => 'Clinical',
        'is_patient_facing' => true,
        'is_appointmentable' => true,
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/department-options')
        ->assertOk()
        ->assertJsonPath('data.0.value', 'General OPD');
});

it('forbids appointment status update without update-status permission', function (): void {
    $creator = makeAppointmentUser();
    $readOnlyUser = User::factory()->create();
    grantAppointmentReadPermission($readOnlyUser);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($readOnlyUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'Not allowed',
        ])
        ->assertForbidden();
});

it('forbids appointment detail update without update permission', function (): void {
    $creator = makeAppointmentUser();
    $readOnlyUser = User::factory()->create();
    grantAppointmentReadPermission($readOnlyUser);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($readOnlyUser)
        ->patchJson('/api/v1/appointments/'.$created['id'], [
            'reason' => 'Not allowed',
        ])
        ->assertForbidden();
});

it('forbids appointment triage handoff without triage permission', function (): void {
    $creator = makeAppointmentUser();
    $userWithoutTriage = User::factory()->create();
    grantAppointmentReadPermission($userWithoutTriage);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($userWithoutTriage)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertForbidden();
});

it('records opd triage and sends the patient to the provider queue', function (): void {
    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $response = $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertOk();

    $response
        ->assertJsonPath('data.status', 'waiting_provider')
        ->assertJsonPath('data.triageVitalsSummary', 'BP 118/74, Pulse 82, Temp 37.1 C')
        ->assertJsonPath('data.triageNotes', 'Stable and ready for provider review.')
        ->assertJsonPath('data.triagedByUserId', $triageUser->id);

    expect(AppointmentModel::query()->find($created['id']))
        ->status->toBe('waiting_provider')
        ->triage_vitals_summary->toBe('BP 118/74, Pulse 82, Temp 37.1 C')
        ->triage_notes->toBe('Stable and ready for provider review.')
        ->triaged_by_user_id->toBe($triageUser->id);
});

it('requires routing context before triage can hand off an unassigned visit', function (): void {
    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'appointmentType' => 'walk_in',
            'department' => null,
            'clinicianUserId' => null,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['department', 'clinicianUserId']);
});

it('records opd triage and routes a walk-in to a named provider', function (): void {
    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $provider = User::factory()->create();
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'appointmentType' => 'walk_in',
            'department' => null,
            'clinicianUserId' => null,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $response = $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
            'department' => 'General OPD',
            'clinicianUserId' => $provider->id,
        ])
        ->assertOk();

    $response
        ->assertJsonPath('data.status', 'waiting_provider')
        ->assertJsonPath('data.department', 'General OPD')
        ->assertJsonPath('data.clinicianUserId', $provider->id);

    expect(AppointmentModel::query()->find($created['id']))
        ->status->toBe('waiting_provider')
        ->department->toBe('General OPD')
        ->clinician_user_id->toBe($provider->id)
        ->triaged_by_user_id->toBe($triageUser->id);
});


it('forbids starting consultation without clinician consultation permission', function (): void {
    $creator = makeAppointmentUser();
    $readOnlyUser = User::factory()->create();
    grantAppointmentReadPermission($readOnlyUser);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertForbidden();

    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);

    $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertOk();

    $this->actingAs($readOnlyUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/start-consultation')
        ->assertForbidden();
});

it('starts provider consultation session from waiting_provider', function (): void {
    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $clinicianUser = User::factory()->create();
    grantAppointmentClinicianPermissions($clinicianUser);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertOk();

    $response = $this->actingAs($clinicianUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/start-consultation')
        ->assertOk();

    $response->assertJsonPath('data.status', 'in_consultation');
    $response->assertJsonPath('data.consultationOwnerUserId', $clinicianUser->id);

    expect(AppointmentModel::query()->find($created['id']))
        ->status->toBe('in_consultation')
        ->consultation_owner_user_id->toBe($clinicianUser->id);
});

it('requires explicit takeover confirmation when another clinician owns the active consultation', function (): void {
    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $firstClinician = User::factory()->create();
    grantAppointmentClinicianPermissions($firstClinician);
    $secondClinician = User::factory()->create();
    grantAppointmentClinicianPermissions($secondClinician);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertOk();

    $this->actingAs($firstClinician)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/start-consultation')
        ->assertOk();

    $response = $this->actingAs($secondClinician)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/start-consultation')
        ->assertStatus(409);

    $response
        ->assertJsonPath('code', 'CONSULTATION_OWNER_CONFLICT')
        ->assertJsonPath('context.consultationOwnerUserId', $firstClinician->id);

    $auditLog = AppointmentAuditLogModel::query()
        ->where('appointment_id', $created['id'])
        ->where('action', 'appointment.consultation.takeover.blocked')
        ->latest('created_at')
        ->first();

    expect($auditLog)->not->toBeNull();
    expect($auditLog?->actor_id)->toBe($secondClinician->id);
    expect(data_get($auditLog?->metadata, 'consultation_takeover_blocked.from_owner_user_id'))->toBe($firstClinician->id);
    expect(data_get($auditLog?->metadata, 'consultation_takeover_blocked.requires_confirmation'))->toBeTrue();
});

it('treats the assigned clinician as consultation owner when legacy active visits are missing owner metadata', function (): void {
    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $assignedClinician = User::factory()->create();
    grantAppointmentClinicianPermissions($assignedClinician);
    $otherClinician = User::factory()->create();
    grantAppointmentClinicianPermissions($otherClinician);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'clinicianUserId' => $assignedClinician->id,
        ]))
        ->assertCreated()
        ->json('data');

    $appointment = AppointmentModel::query()->findOrFail($created['id']);
    $appointment->update([
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(12),
        'consultation_owner_user_id' => null,
        'consultation_owner_assigned_at' => null,
    ]);

    $this->actingAs($otherClinician)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/start-consultation')
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONSULTATION_OWNER_CONFLICT')
        ->assertJsonPath('context.consultationOwnerUserId', $assignedClinician->id);

    $this->actingAs($assignedClinician)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/start-consultation')
        ->assertOk()
        ->assertJsonPath('data.consultationOwnerUserId', $assignedClinician->id);

    expect($appointment->fresh())
        ->consultation_owner_user_id->toBe($assignedClinician->id);
});

it('allows consultation takeover when confirmation is provided', function (): void {
    Notification::fake();

    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $firstClinician = User::factory()->create();
    grantAppointmentClinicianPermissions($firstClinician);
    $secondClinician = User::factory()->create();
    grantAppointmentClinicianPermissions($secondClinician);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertOk();

    $this->actingAs($firstClinician)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/start-consultation')
        ->assertOk();

    $response = $this->actingAs($secondClinician)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/start-consultation', [
            'forceTakeover' => true,
            'takeoverReason' => 'On-call handoff accepted to continue care.',
        ])
        ->assertOk();

    $response
        ->assertJsonPath('data.status', 'in_consultation')
        ->assertJsonPath('data.consultationOwnerUserId', $secondClinician->id)
        ->assertJsonPath('data.consultationTakeoverCount', 1);

    Notification::assertSentTo(
        $firstClinician,
        AppointmentConsultationTakenOverNotification::class,
        function (AppointmentConsultationTakenOverNotification $notification) use ($firstClinician, $secondClinician) {
            $mail = $notification->toMail($firstClinician);
            $mailBody = implode(' ', array_merge($mail->introLines, $mail->outroLines));

            expect($mail->subject)->toBe('Consultation taken over');
            expect($mailBody)->toContain('The active consultation for appointment');
            expect($mailBody)->toContain($secondClinician->name);

            return true;
        },
    );

    expect(AppointmentModel::query()->find($created['id']))
        ->consultation_owner_user_id->toBe($secondClinician->id)
        ->consultation_takeover_count->toBe(1);
});

it('blocks provider workflow updates when active consultation is owned by another clinician', function (): void {
    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $firstClinician = User::factory()->create();
    grantAppointmentClinicianPermissions($firstClinician);
    $secondClinician = User::factory()->create();
    grantAppointmentClinicianPermissions($secondClinician);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertOk();

    $this->actingAs($firstClinician)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/start-consultation')
        ->assertOk();

    $this->actingAs($secondClinician)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/provider-workflow', [
            'status' => 'completed',
            'reason' => null,
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONSULTATION_OWNER_CONFLICT');
});

it('blocks the generic status endpoint from cancelling another clinician\'s active consultation', function (): void {
    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $clinicianUser = User::factory()->create();
    grantAppointmentClinicianPermissions($clinicianUser);
    // A receptionist-style user: only holds appointments.update-status (needed for
    // check-in/cancel), not any consultation-ownership-aware permission.
    $receptionUser = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertOk();

    $this->actingAs($clinicianUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/start-consultation')
        ->assertOk();

    $this->actingAs($receptionUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'Patient requested cancellation',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONSULTATION_OWNER_REQUIRED');

    expect(AppointmentModel::query()->find($created['id']))->status->toBe('in_consultation');
});

it('lets the consultation owner cancel their own active consultation via the generic status endpoint', function (): void {
    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $clinicianUser = User::factory()->create();
    grantAppointmentClinicianPermissions($clinicianUser);
    grantAppointmentWorkflowPermissions($clinicianUser);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertOk();

    $this->actingAs($clinicianUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/start-consultation')
        ->assertOk();

    $this->actingAs($clinicianUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'Clinician cancelled visit',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('lets a facility super admin cancel another clinician\'s active consultation via the generic status endpoint', function (): void {
    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $clinicianUser = User::factory()->create();
    grantAppointmentClinicianPermissions($clinicianUser);
    $superAdmin = makeFacilitySuperAdminUser();
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertOk();

    $this->actingAs($clinicianUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/start-consultation')
        ->assertOk();

    $this->actingAs($superAdmin)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'Administrative override',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('still allows cancelling a not-yet-claimed visit via the generic status endpoint', function (): void {
    $creator = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'No longer needed',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('returns provider session to triage with handoff reason', function (): void {
    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $clinicianUser = User::factory()->create();
    grantAppointmentClinicianPermissions($clinicianUser);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertOk();

    $this->actingAs($clinicianUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/start-consultation')
        ->assertOk();

    $response = $this->actingAs($clinicianUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/provider-workflow', [
            'status' => 'waiting_triage',
            'reason' => 'Repeat vitals and blood sugar before provider continues.',
        ])
        ->assertOk();

    $response
        ->assertJsonPath('data.status', 'waiting_triage')
        ->assertJsonPath('data.statusReason', 'Repeat vitals and blood sugar before provider continues.');
});

it('returns active consultation to provider queue', function (): void {
    $creator = makeAppointmentUser();
    $triageUser = User::factory()->create();
    grantAppointmentTriagePermissions($triageUser);
    $clinicianUser = User::factory()->create();
    grantAppointmentClinicianPermissions($clinicianUser);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($triageUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/triage', [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Stable and ready for provider review.',
        ])
        ->assertOk();

    $this->actingAs($clinicianUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/start-consultation')
        ->assertOk();

    $response = $this->actingAs($clinicianUser)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/provider-workflow', [
            'status' => 'waiting_provider',
            'reason' => 'Patient stepped out briefly.',
        ])
        ->assertOk();

    $response->assertJsonPath('data.status', 'waiting_provider');

    // consultation_started_at must survive this transition — it's the only
    // signal that distinguishes "on hold" (already seen a provider) from
    // "never yet seen a provider" once back at waiting_provider.
    expect(AppointmentModel::query()->find($created['id'])->consultation_started_at)->not->toBeNull();
});

it('completes visit from active provider session', function (): void {
    $clinicianUser = User::factory()->create();
    grantAppointmentClinicianPermissions($clinicianUser);
    $clinicianUser->givePermissionTo('medical.records.finalize');
    $patient = makePatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'clinician_user_id' => $clinicianUser->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subMinutes(30)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'General consultation',
        'notes' => null,
        'status' => 'in_consultation',
        'status_reason' => null,
        'consultation_owner_user_id' => $clinicianUser->id,
        'consultation_owner_assigned_at' => now()->toDateTimeString(),
        'consultation_started_at' => now()->toDateTimeString(),
    ]);

    $record = $this->actingAs($clinicianUser)
        ->postJson('/api/v1/medical-records', consultationNotePayload($patient->id, $appointment->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($clinicianUser)
        ->patchJson('/api/v1/medical-records/'.$record['id'].'/status', [
            'status' => 'finalized',
            'reason' => null,
        ])
        ->assertOk();

    $response = $this->actingAs($clinicianUser)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/provider-workflow', [
            'status' => 'completed',
            'reason' => null,
        ])
        ->assertOk();

    $response->assertJsonPath('data.status', 'completed');
});

it('blocks visit completion when linked consultation note is still draft', function (): void {
    $clinicianUser = User::factory()->create();
    grantAppointmentClinicianPermissions($clinicianUser);
    $patient = makePatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'clinician_user_id' => $clinicianUser->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subMinutes(30)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'General consultation',
        'notes' => null,
        'status' => 'in_consultation',
        'status_reason' => null,
        'consultation_owner_user_id' => $clinicianUser->id,
        'consultation_owner_assigned_at' => now()->toDateTimeString(),
        'consultation_started_at' => now()->toDateTimeString(),
    ]);

    $this->actingAs($clinicianUser)
        ->postJson('/api/v1/medical-records', consultationNotePayload($patient->id, $appointment->id))
        ->assertCreated();

    $this->actingAs($clinicianUser)
        ->patchJson('/api/v1/appointments/'.$appointment->id.'/provider-workflow', [
            'status' => 'completed',
            'reason' => null,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Finalize the consultation note before closing this visit.')
        ->assertJsonPath('context.requiresFinalizedConsultationNote', true)
        ->assertJsonPath('context.hasDraftConsultationNote', true)
        ->assertJsonPath('context.hasSignedConsultationNote', false);
});

it('can create appointment for active patient', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.status', 'scheduled');
});

it('keeps visit reason separate from workflow note fields', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'reason' => 'Consultation',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.reason', 'Consultation')
        ->assertJsonPath('data.statusReason', null)
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'Patient request',
        ])
        ->assertOk()
        ->assertJsonPath('data.reason', 'Consultation')
        ->assertJsonPath('data.statusReason', 'Patient request');

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.reason', 'Consultation')
        ->assertJsonPath('data.statusReason', 'Patient request');
});

it('rejects creating a second active appointment for the same patient on the same day', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();
    $scheduledDate = now()->addDay()->setTime(9, 0, 0);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => $scheduledDate->toDateTimeString(),
            'department' => 'General OPD',
        ]))
        ->assertCreated()
        ->json('data');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => $scheduledDate->copy()->setTime(14, 0, 0)->toDateTimeString(),
            'department' => 'Dental Clinic',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('context.activeAppointmentConflict.patientId', $patient->id)
        ->assertJsonPath('context.activeAppointmentConflict.appointmentNumber', $created['appointmentNumber'])
        ->assertJsonValidationErrors(['patientId', 'scheduledAt']);

    // Still just `scheduled` — nothing has checked this patient in yet, so
    // "has an appointment scheduled" is accurate wording.
    expect($response->json('message'))->toContain('has an appointment')->toContain('scheduled');
    expect($response->json('message'))->not->toContain('checked in');
});

it('describes the conflict as an active visit, not an appointment, once the patient has checked in', function (): void {
    // Patient flow redesign: the conflict message previously said "already
    // has an active appointment" regardless of the conflicting record's
    // status — misleading once the patient has actually arrived and is
    // being seen, not merely booked. See AppointmentConflictMessageFormatter.
    $user = makeAppointmentUser();
    $patient = makePatient();
    $scheduledDate = now()->addDay()->setTime(9, 0, 0);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => $scheduledDate->toDateTimeString(),
            'department' => 'Emergency',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/check-in', [])
        ->assertOk();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => $scheduledDate->copy()->setTime(14, 0, 0)->toDateTimeString(),
        ]))
        ->assertStatus(422);

    expect($response->json('message'))
        ->toContain('checked in')
        ->toContain('Emergency')
        ->not->toContain('has an appointment');
});

it('allows another appointment after the existing same-day appointment is cancelled', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();
    $scheduledDate = now()->addDay()->setTime(9, 30, 0);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => $scheduledDate->toDateTimeString(),
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'Rescheduled',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => $scheduledDate->copy()->setTime(13, 0, 0)->toDateTimeString(),
            'department' => 'General OPD',
        ]))
        ->assertCreated();
});

it('warns but does not block creating an appointment on a different day than an existing unresolved appointment for the same patient', function (): void {
    // Reception scheduling-duplicate audit (2026-08-12) — the same-day hard
    // block above is correct but narrow: two Radiology appointments two
    // weeks apart previously created with zero signal to the receptionist
    // that the first one still existed. This is a soft, non-blocking
    // warning, not a conflict — the second create must still succeed.
    $user = makeAppointmentUser();
    $patient = makePatient();

    $first = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => now()->addDays(2)->setTime(9, 0, 0)->toDateTimeString(),
            'department' => 'Radiology',
        ]))
        ->assertCreated()
        ->json('data');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => now()->addDays(16)->setTime(14, 0, 0)->toDateTimeString(),
            'department' => 'Radiology',
        ]))
        ->assertCreated();

    $response->assertJsonPath('warnings.0.code', 'appointment.upcoming_conflict');
    $response->assertJsonPath('warnings.0.existingAppointment.id', $first['id']);
    expect($response->json('warnings.0.message'))
        ->toContain('another appointment')
        ->toContain('Radiology');
});

it('returns no warnings when the patient has no other unresolved appointments', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => now()->addDays(2)->setTime(9, 0, 0)->toDateTimeString(),
        ]))
        ->assertCreated();

    $response->assertJsonPath('warnings', []);
});

it('does not warn about an upcoming appointment once it has been cancelled', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $first = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => now()->addDays(2)->setTime(9, 0, 0)->toDateTimeString(),
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$first['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'Patient rescheduled',
        ])
        ->assertOk();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => now()->addDays(16)->setTime(14, 0, 0)->toDateTimeString(),
        ]))
        ->assertCreated();

    $response->assertJsonPath('warnings', []);
});

it('rejects moving an appointment onto the same day as another active appointment for the same patient', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();
    $targetDate = now()->addDays(2)->setTime(10, 0, 0);

    $first = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => $targetDate->toDateTimeString(),
            'department' => 'General OPD',
        ]))
        ->assertCreated()
        ->json('data');

    $second = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'scheduledAt' => $targetDate->copy()->addDay()->toDateTimeString(),
            'department' => 'Dental Clinic',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$second['id'], [
            'scheduledAt' => $targetDate->copy()->setTime(15, 0, 0)->toDateTimeString(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors(['patientId', 'scheduledAt']);

    expect(AppointmentModel::query()->findOrFail($second['id'])->scheduled_at?->format('Y-m-d H:i:s'))
        ->toBe($targetDate->copy()->addDay()->format('Y-m-d H:i:s'));
});

it('rejects appointment creation when patient is missing', function (): void {
    $user = makeAppointmentUser();

    $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload((string) Str::uuid()))
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors(['patientId']);
});

it('rejects appointment creation for inactive patient', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient(['status' => 'inactive']);

    $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['patientId']);
});

it('fetches appointment by id', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.id', $created['id']);
});

it('creates a post-discharge follow-up appointment linked to the source admission', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();
    $admission = makeAdmission($patient->id);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'reason' => 'Post-discharge review',
            'sourceAdmissionId' => $admission->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.sourceAdmissionId', $admission->id);

    $appointment = AppointmentModel::query()->findOrFail($response->json('data.id'));
    expect($appointment->source_admission_id)->toBe($admission->id);
});

it('rejects a post-discharge follow-up appointment when the source admission belongs to another patient', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();
    $otherPatient = makePatient([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'phone' => '+255700000111',
    ]);
    $admission = makeAdmission($otherPatient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'sourceAdmissionId' => $admission->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sourceAdmissionId']);
});

it('rejects a post-discharge follow-up appointment when the source admission is not discharged', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();
    $admission = makeAdmission($patient->id, [
        'status' => 'admitted',
        'discharged_at' => null,
        'discharge_destination' => null,
        'follow_up_plan' => null,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id, [
            'sourceAdmissionId' => $admission->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sourceAdmissionId']);
});

it('updates appointment fields', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $newDateTime = now()->addDays(2)->toDateTimeString();

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'], [
            'scheduledAt' => $newDateTime,
            'reason' => 'Updated consultation reason',
        ])
        ->assertOk()
        ->assertJsonPath('data.reason', 'Updated consultation reason');

    expect((string) $response->json('data.scheduledAt'))
        ->toStartWith(str_replace(' ', 'T', $newDateTime));
});

it('rejects status lifecycle fields on appointment detail update endpoint', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'], [
            'notes' => 'Should not persist',
            'status' => 'cancelled',
            'statusReason' => 'Lifecycle update attempt',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status', 'statusReason']);

    $appointment = AppointmentModel::query()->findOrFail($created['id']);
    expect($appointment->status)->toBe('scheduled');
    expect($appointment->notes)->toBe('Follow-up required');
});

it('rejects triage fields on the generic appointment update endpoint, closing the appointments.update bypass of appointments.record-triage', function (): void {
    $creator = makeAppointmentUser();
    $registrationClerk = User::factory()->create();
    grantAppointmentReadPermission($registrationClerk);
    $registrationClerk->givePermissionTo('appointments.update');
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk();

    $this->actingAs($registrationClerk)
        ->patchJson('/api/v1/appointments/'.$created['id'], [
            'triageVitalsSummary' => 'BP 118/74, Pulse 82, Temp 37.1 C',
            'triageNotes' => 'Recorded by front desk without triage permission.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['triageVitalsSummary', 'triageNotes']);

    $appointment = AppointmentModel::query()->findOrFail($created['id']);
    expect($appointment->triage_vitals_summary)->toBeNull();
    expect($appointment->triage_notes)->toBeNull();
    expect($appointment->triaged_at)->toBeNull();
    expect($appointment->triaged_by_user_id)->toBeNull();
});

it('updates appointment status', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'Patient request',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.statusReason', 'Patient request');
});

it('enforces reason for no_show status and writes transition metadata', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'no_show',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'no_show',
            'reason' => 'Patient did not arrive',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'no_show')
        ->assertJsonPath('data.statusReason', 'Patient did not arrive');

    $statusLog = AppointmentAuditLogModel::query()
        ->where('appointment_id', $created['id'])
        ->where('action', 'appointment.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('scheduled');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('no_show');
    expect($statusLog?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('writes appointment audit logs for create update and status change', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/appointments/'.$created['id'], [
        'reason' => 'Updated reason for audit trail',
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
        'status' => 'completed',
        'reason' => 'visit done',
    ])->assertOk();

    $logs = AppointmentAuditLogModel::query()
        ->where('appointment_id', $created['id'])
        ->orderBy('created_at')
        ->get();

    expect($logs)->toHaveCount(3);
    expect($logs->pluck('action')->all())->toContain(
        'appointment.created',
        'appointment.updated',
        'appointment.status.updated',
    );
    expect($logs->first()->actor_id)->toBe($user->id);
});

it('lists appointment audit logs when authorized', function (): void {
    $user = makeAppointmentUser();
    $user->givePermissionTo('appointments.view-audit-logs');
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/appointments/'.$created['id'], [
        'reason' => 'Audit pagination check',
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
        'status' => 'cancelled',
        'reason' => 'schedule conflict',
    ])->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/'.$created['id'].'/audit-logs?perPage=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('data.0.action', 'appointment.status.updated')
        ->assertJsonPath('data.1.action', 'appointment.updated');
});

it('forbids appointment audit log access without permission', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('forbids appointment audit logs when gate override denies', function (): void {
    Gate::define('appointments.view-audit-logs', static fn (): bool => false);

    $user = makeAppointmentUser();
    $user->givePermissionTo('appointments.view-audit-logs');
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('returns 404 for appointment audit logs of unknown id', function (): void {
    $user = makeAppointmentUser();
    $user->givePermissionTo('appointments.view-audit-logs');

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/c5f293f0-fd95-4e8f-b583-35ffaf1740a9/audit-logs')
        ->assertNotFound();
});

it('lists and filters appointments', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    AppointmentModel::query()->create([
        'appointment_number' => 'APT20260225AAAAAA',
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'General consultation',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    AppointmentModel::query()->create([
        'appointment_number' => 'APT20260225BBBBBB',
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Laboratory review',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments?q=General&status=scheduled')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.reason', 'General consultation')
        ->assertJsonPath('data.0.status', 'scheduled');
});

it('searches appointments by patient name and number', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient([
        'first_name' => 'Zainab',
        'last_name' => 'Okello',
        'patient_number' => 'PT20260521SEARCH',
    ]);

    AppointmentModel::query()->create([
        'appointment_number' => 'APT20260521SEARCH1',
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'General OPD',
        'scheduled_at' => now()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Follow-up review',
        'notes' => null,
        'status' => 'waiting_provider',
        'status_reason' => null,
    ]);

    AppointmentModel::query()->create([
        'appointment_number' => 'APT20260521OTHER01',
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'General OPD',
        'scheduled_at' => now()->addHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Unrelated visit',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments?q=Zainab+Okello&status=waiting_provider')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.appointmentNumber', 'APT20260521SEARCH1');

    $this->actingAs($user)
        ->getJson('/api/v1/appointments?q=PT20260521SEARCH')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('lists department pool appointments without an assigned clinician', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();
    $provider = User::factory()->create();

    AppointmentModel::query()->create([
        'appointment_number' => 'APT20260521POOL01',
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'General OPD',
        'scheduled_at' => now()->toDateTimeString(),
        'checked_in_at' => now()->subMinutes(20)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Clinic pool pickup',
        'notes' => null,
        'status' => 'waiting_provider',
        'status_reason' => null,
    ]);

    AppointmentModel::query()->create([
        'appointment_number' => 'APT20260521ASSIGN',
        'patient_id' => $patient->id,
        'clinician_user_id' => $provider->id,
        'department' => 'General OPD',
        'scheduled_at' => now()->toDateTimeString(),
        'checked_in_at' => now()->subMinutes(10)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Named provider visit',
        'notes' => null,
        'status' => 'waiting_provider',
        'status_reason' => null,
    ]);

    AppointmentModel::query()->create([
        'appointment_number' => 'APT20260521OTHERDEPT',
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Dental Clinic',
        'scheduled_at' => now()->toDateTimeString(),
        'checked_in_at' => now()->subMinutes(5)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Other clinic pool',
        'notes' => null,
        'status' => 'waiting_provider',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments?department=General+OPD&unassignedClinician=true&status=waiting_provider')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.appointmentNumber', 'APT20260521POOL01');

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/status-counts?department=General+OPD&unassignedClinician=true')
        ->assertOk()
        ->assertJsonPath('data.waiting_provider', 1)
        ->assertJsonPath('data.total', 1);
});

it('scopes appointment status counts by triage priority without losing status buckets', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    AppointmentModel::query()->create([
        'appointment_number' => 'APT20260521P1TRIAGE',
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'General OPD',
        'scheduled_at' => now()->toDateTimeString(),
        'checked_in_at' => now()->subMinutes(15)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'High priority triage',
        'notes' => null,
        'status' => 'waiting_triage',
        'status_reason' => null,
        'triage_category' => 'P1',
    ]);

    AppointmentModel::query()->create([
        'appointment_number' => 'APT20260521P1PROVIDER',
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'General OPD',
        'scheduled_at' => now()->toDateTimeString(),
        'checked_in_at' => now()->subMinutes(10)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'High priority provider handoff',
        'notes' => null,
        'status' => 'waiting_provider',
        'status_reason' => null,
        'triage_category' => 'P1',
    ]);

    AppointmentModel::query()->create([
        'appointment_number' => 'APT20260521P3PROVIDER',
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'General OPD',
        'scheduled_at' => now()->toDateTimeString(),
        'checked_in_at' => now()->subMinutes(5)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Routine provider handoff',
        'notes' => null,
        'status' => 'waiting_provider',
        'status_reason' => null,
        'triage_category' => 'P3',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/status-counts?triageCategory=P1')
        ->assertOk()
        ->assertJsonPath('data.waiting_triage', 1)
        ->assertJsonPath('data.waiting_provider', 1)
        ->assertJsonPath('data.triage_categories.P1', 2)
        ->assertJsonPath('data.triage_categories.P3', 0)
        ->assertJsonPath('data.total', 2);
});

it('stamps appointment tenant and facility scope when created under resolved platform scope', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    [$tenantId, $facilityId] = seedAppointmentPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-APT',
        facilityName: 'Dar OPD',
    );

    $created = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZH',
            'X-Facility-Code' => 'DAR-APT',
        ])
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $row = AppointmentModel::query()->findOrFail($created['id']);

    expect($row->tenant_id)->toBe($tenantId);
    expect($row->facility_id)->toBe($facilityId);
});

it('filters appointment reads by facility scope when platform multi facility scoping is enabled', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', true);

    $user = makeAppointmentUser();
    $patient = makePatient();

    [$tenantId, $facilityId] = seedAppointmentPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-APT',
        facilityName: 'Nairobi Clinic',
    );

    [, $otherFacilityId] = seedAppointmentPlatformScopeFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-APT',
        facilityName: 'Mombasa Clinic',
    );

    $visible = AppointmentModel::query()->create([
        'appointment_number' => 'APT20260225SCOPED1',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Scoped visible appointment',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    $hidden = AppointmentModel::query()->create([
        'appointment_number' => 'APT20260225SCOPED2',
        'tenant_id' => $tenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Scoped hidden appointment',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-APT',
        ])
        ->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.reason', 'Scoped visible appointment');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-APT',
        ])
        ->getJson('/api/v1/appointments/'.$hidden->id)
        ->assertNotFound();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-APT',
        ])
        ->patchJson('/api/v1/appointments/'.$hidden->id, [
            'reason' => 'Attempted cross-facility update',
        ])
        ->assertNotFound();
});

it('filters appointment reads by facility scope when enabled via feature flag override', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', false);

    $user = makeAppointmentUser();
    $patient = makePatient();

    [$tenantId, $facilityId] = seedAppointmentPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-APT',
        facilityName: 'Nairobi Clinic',
    );

    [, $otherFacilityId] = seedAppointmentPlatformScopeFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-APT',
        facilityName: 'Mombasa Clinic',
    );

    DB::table('feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_name' => 'platform.multi_facility_scoping',
        'scope_type' => 'country',
        'scope_key' => 'KE',
        'enabled' => true,
        'reason' => 'enable scoping for Kenya rollout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $visible = AppointmentModel::query()->create([
        'appointment_number' => 'APT20260225SCOPED3',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Override visible appointment',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    AppointmentModel::query()->create([
        'appointment_number' => 'APT20260225SCOPED4',
        'tenant_id' => $tenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Override hidden appointment',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-APT',
        ])
        ->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.reason', 'Override visible appointment');
});

it('filters appointment reads by tenant scope when multi tenant isolation is enabled without facility scoping', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', false);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeAppointmentUser();
    $patient = makePatient();

    [$tenantId, $facilityId] = seedAppointmentPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-APT',
        facilityName: 'Nairobi Clinic',
    );

    [$otherTenantId, $otherFacilityId] = seedAppointmentPlatformScopeFacility(
        tenantCode: 'UGH',
        tenantName: 'Uganda Health Network',
        countryCode: 'UG',
        facilityCode: 'KLA-APT',
        facilityName: 'Kampala Clinic',
    );

    DB::table('feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_name' => 'platform.multi_tenant_isolation',
        'scope_type' => 'country',
        'scope_key' => 'KE',
        'enabled' => true,
        'reason' => 'enable tenant isolation for Kenya rollout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $visible = AppointmentModel::query()->create([
        'appointment_number' => 'APT20260225TENANT1',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Tenant visible appointment',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    AppointmentModel::query()->create([
        'appointment_number' => 'APT20260225TENANT2',
        'tenant_id' => $otherTenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Tenant hidden appointment',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
        ])
        ->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.reason', 'Tenant visible appointment');
});

it('blocks appointment creation in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeAppointmentUser();
    $patient = makePatient();

    $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks appointment update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = AppointmentModel::query()->create([
        'appointment_number' => 'APT20260225GUARD01',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Guard target',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created->id, [
            'reason' => 'Attempted guarded update',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks appointment status update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = AppointmentModel::query()->create([
        'appointment_number' => 'APT20260225GUARD02',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Guard status target',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created->id.'/status', [
            'status' => 'completed',
            'reason' => 'done',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('can create list and update appointment referral when authorized', function (): void {
    $user = makeAppointmentUser();
    $user->givePermissionTo('appointments.manage-referrals');

    $patient = makePatient();

    $appointment = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments/'.$appointment['id'].'/referrals', [
            'referralType' => 'internal',
            'priority' => 'urgent',
            'targetDepartment' => 'Cardiology',
            'referralReason' => 'Requires specialist review',
            'clinicalNotes' => 'ECG changes noted',
        ])
        ->assertCreated()
        ->assertJsonPath('data.referralType', 'internal')
        ->assertJsonPath('data.status', 'requested')
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/'.$appointment['id'].'/referrals')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $created['id']);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/'.$appointment['id'].'/referral-status-counts')
        ->assertOk()
        ->assertJsonPath('data.requested', 1)
        ->assertJsonPath('data.total', 1);

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/referrals/'.$created['id'].'/status', [
            'status' => 'accepted',
            'reason' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');
});

it('resolves appointment referral target facility by code within scoped tenant network', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', true);

    $user = makeAppointmentUser();
    $user->givePermissionTo('appointments.manage-referrals');

    [$tenantId, $sourceFacilityId] = seedAppointmentPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'TZ-APT-NET',
        tenantName: 'TZ Appointment Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-APT',
        facilityName: 'Dar Outpatient',
    );

    [, $targetFacilityId] = seedAppointmentPlatformScopeFacility(
        tenantCode: 'TZ-APT-NET',
        tenantName: 'TZ Appointment Network',
        countryCode: 'TZ',
        facilityCode: 'MSA-APT',
        facilityName: 'Mombasa Referral Hospital',
    );

    $patient = makePatient();

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-NET-OUT-001',
        'tenant_id' => $tenantId,
        'facility_id' => $sourceFacilityId,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Referral source',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ])->toArray();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZ-APT-NET',
            'X-Facility-Code' => 'DAR-APT',
        ])
        ->postJson('/api/v1/appointments/'.$appointment['id'].'/referrals', [
            'referralType' => 'internal',
            'priority' => 'urgent',
            'targetDepartment' => 'Cardiology',
            'targetFacilityCode' => 'MSA-APT',
            'referralReason' => 'Network transfer',
        ])
        ->assertCreated()
        ->assertJsonPath('data.targetFacilityId', $targetFacilityId)
        ->assertJsonPath('data.targetFacilityCode', 'MSA-APT')
        ->assertJsonPath('data.targetFacilityName', 'Mombasa Referral Hospital');

    $row = DB::table('appointment_referrals')->latest('created_at')->first();
    expect($row)->not->toBeNull();
    expect((string) $row->facility_id)->toBe($sourceFacilityId);
    expect((string) $row->target_facility_id)->toBe($targetFacilityId);
    expect((string) $row->target_facility_code)->toBe('MSA-APT');
});

it('lists appointment referral network queues across inbound and outbound scoped facilities', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', true);

    $user = makeAppointmentUser();
    $user->givePermissionTo('appointments.manage-referrals');

    [$tenantId, $sourceFacilityId] = seedAppointmentPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'TZ-APT-NET2',
        tenantName: 'TZ Appointment Network 2',
        countryCode: 'TZ',
        facilityCode: 'DAR-APT',
        facilityName: 'Dar Outpatient',
    );

    [, $remoteFacilityId] = seedAppointmentPlatformScopeFacility(
        tenantCode: 'TZ-APT-NET2',
        tenantName: 'TZ Appointment Network 2',
        countryCode: 'TZ',
        facilityCode: 'KIA-APT',
        facilityName: 'Kigamboni Referral',
    );

    $patient = makePatient();

    $outboundAppointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-NET2-OUT-001',
        'tenant_id' => $tenantId,
        'facility_id' => $sourceFacilityId,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Outbound source',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ])->toArray();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZ-APT-NET2',
            'X-Facility-Code' => 'DAR-APT',
        ])
        ->postJson('/api/v1/appointments/'.$outboundAppointment['id'].'/referrals', [
            'referralType' => 'internal',
            'priority' => 'urgent',
            'targetFacilityCode' => 'KIA-APT',
            'targetDepartment' => 'Neurology',
        ])
        ->assertCreated();

    $inboundAppointmentId = (string) Str::uuid();
    DB::table('appointments')->insert([
        'id' => $inboundAppointmentId,
        'appointment_number' => 'APT-NET-INBOUND-01',
        'tenant_id' => $tenantId,
        'facility_id' => $remoteFacilityId,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Inbound source',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('appointment_referrals')->insert([
        'id' => (string) Str::uuid(),
        'appointment_id' => $inboundAppointmentId,
        'referral_number' => 'RFL-NET-INBOUND-01',
        'tenant_id' => $tenantId,
        'facility_id' => $remoteFacilityId,
        'referral_type' => 'internal',
        'priority' => 'critical',
        'target_department' => 'ICU',
        'target_facility_id' => $sourceFacilityId,
        'target_facility_code' => 'DAR-APT',
        'target_facility_name' => 'Dar Outpatient',
        'target_clinician_user_id' => null,
        'referral_reason' => 'Inbound transfer',
        'clinical_notes' => null,
        'handoff_notes' => null,
        'requested_at' => now()->subHour()->toDateTimeString(),
        'accepted_at' => null,
        'handed_off_at' => null,
        'completed_at' => null,
        'status' => 'requested',
        'status_reason' => null,
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZ-APT-NET2',
            'X-Facility-Code' => 'DAR-APT',
        ])
        ->getJson('/api/v1/appointments/referrals/network?networkMode=all&perPage=20')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZ-APT-NET2',
            'X-Facility-Code' => 'DAR-APT',
        ])
        ->getJson('/api/v1/appointments/referrals/network?networkMode=inbound&perPage=20')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.targetFacilityCode', 'DAR-APT');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZ-APT-NET2',
            'X-Facility-Code' => 'DAR-APT',
        ])
        ->getJson('/api/v1/appointments/referrals/network?networkMode=outbound&perPage=20')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.targetFacilityCode', 'KIA-APT');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZ-APT-NET2',
            'X-Facility-Code' => 'DAR-APT',
        ])
        ->getJson('/api/v1/appointments/referrals/network/status-counts?networkMode=all')
        ->assertOk()
        ->assertJsonPath('data.requested', 2)
        ->assertJsonPath('data.total', 2);
});

it('rejects appointment referral create when target facility code is invalid in scope', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', true);

    $user = makeAppointmentUser();
    $user->givePermissionTo('appointments.manage-referrals');

    [$tenantId, $sourceFacilityId] = seedAppointmentPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'TZ-APT-NET3',
        tenantName: 'TZ Appointment Network 3',
        countryCode: 'TZ',
        facilityCode: 'DAR-APT',
        facilityName: 'Dar Outpatient',
    );

    $patient = makePatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-NET3-OUT-001',
        'tenant_id' => $tenantId,
        'facility_id' => $sourceFacilityId,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Invalid target test',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ])->toArray();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZ-APT-NET3',
            'X-Facility-Code' => 'DAR-APT',
        ])
        ->postJson('/api/v1/appointments/'.$appointment['id'].'/referrals', [
            'referralType' => 'internal',
            'priority' => 'urgent',
            'targetFacilityCode' => 'UNKNOWN-APT',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors(['targetFacilityCode']);
});

it('allows appointment referral audit logs and csv export when authorized', function (): void {
    $user = makeAppointmentUser();
    $user->givePermissionTo('appointments.manage-referrals');
    $user->givePermissionTo('appointments.view-referral-audit-logs');

    $patient = makePatient();
    $appointment = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments/'.$appointment['id'].'/referrals', [
            'referralType' => 'external',
            'priority' => 'critical',
            'targetFacilityName' => 'Muhimbili National Hospital',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/referrals/'.$created['id'].'/status', [
            'status' => 'in_progress',
            'reason' => null,
            'handoffNotes' => 'Ambulance transfer started',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/'.$appointment['id'].'/referrals/'.$created['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'appointment.referral.status.updated');

    $response = $this->actingAs($user)
        ->get('/api/v1/appointments/'.$appointment['id'].'/referrals/'.$created['id'].'/audit-logs/export');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
});

it('forbids appointment referral audit logs without permission', function (): void {
    $user = makeAppointmentUser();
    $user->givePermissionTo('appointments.manage-referrals');

    $patient = makePatient();
    $appointment = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments/'.$appointment['id'].'/referrals', [
            'referralType' => 'internal',
            'priority' => 'routine',
            'targetDepartment' => 'Physiotherapy',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/'.$appointment['id'].'/referrals/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('blocks appointment referral creation in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeAppointmentUser();
    $user->givePermissionTo('appointments.manage-referrals');
    $patient = makePatient();

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT20260302REFGUARD',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Referral guard target',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/appointments/'.$appointment->id.'/referrals', [
            'referralType' => 'internal',
            'priority' => 'urgent',
            'targetDepartment' => 'Cardiology',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

/**
 * @return array{0:string,1:string}
 */
function seedAppointmentPlatformScopeAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedAppointmentPlatformScopeFacility(
        tenantCode: $tenantCode,
        tenantName: $tenantName,
        countryCode: $countryCode,
        facilityCode: $facilityCode,
        facilityName: $facilityName,
    );

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'clinician',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}

/**
 * @return array{0:string,1:string}
 */
function seedAppointmentPlatformScopeFacility(
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    $tenant = DB::table('tenants')->where('code', $tenantCode)->first();

    if ($tenant === null) {
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'code' => $tenantCode,
            'name' => $tenantName,
            'country_code' => $countryCode,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        $tenantId = (string) $tenant->id;
    }

    $facilityId = (string) Str::uuid();
    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => $facilityCode,
        'name' => $facilityName,
        'facility_type' => 'clinic',
        'timezone' => 'Africa/Nairobi',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}


