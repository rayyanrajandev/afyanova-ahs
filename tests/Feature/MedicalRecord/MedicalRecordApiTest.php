<?php

use App\Models\User;
use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Modules\Encounter\Infrastructure\Models\EncounterAuditLogModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentReferralModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordAuditLogModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordSignerAttestationModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordVersionModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);
});

function makeMedicalRecordPatient(array $overrides = []): PatientModel
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

function makeMedicalRecordAppointment(string $patientId, array $overrides = []): AppointmentModel
{
    return AppointmentModel::query()->create(array_merge([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
    ], $overrides));
}

function makeMedicalRecordAdmission(string $patientId): AdmissionModel
{
    return AdmissionModel::query()->create([
        'admission_number' => 'ADM'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward A',
        'bed' => 'A-02',
        'admitted_at' => now()->subHours(2)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Observation',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);
}

function makeMedicalRecordAppointmentReferral(string $appointmentId, array $overrides = []): AppointmentReferralModel
{
    return AppointmentReferralModel::query()->create(array_merge([
        'appointment_id' => $appointmentId,
        'referral_number' => 'REF'.now()->format('Ymd').strtoupper(Str::random(6)),
        'referral_type' => 'internal',
        'priority' => 'routine',
        'target_department' => 'Surgery',
        'target_facility_name' => null,
        'target_clinician_user_id' => null,
        'referral_reason' => 'Needs specialty review',
        'clinical_notes' => null,
        'handoff_notes' => null,
        'requested_at' => now()->subMinutes(20)->toDateTimeString(),
        'accepted_at' => null,
        'handed_off_at' => null,
        'completed_at' => null,
        'status' => 'requested',
        'status_reason' => null,
        'metadata' => null,
    ], $overrides));
}

function makeMedicalRecordTheatreProcedure(string $patientId, array $overrides = []): TheatreProcedureModel
{
    $operatingClinicianUserId = array_key_exists('operating_clinician_user_id', $overrides)
        ? $overrides['operating_clinician_user_id']
        : User::factory()->create()->id;
    $anesthetistUserId = array_key_exists('anesthetist_user_id', $overrides)
        ? $overrides['anesthetist_user_id']
        : User::factory()->create()->id;

    return TheatreProcedureModel::query()->create(array_merge([
        'procedure_number' => 'THR'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => null,
        'admission_id' => null,
        'theatre_procedure_catalog_item_id' => null,
        'procedure_type' => 'minor_procedure',
        'procedure_name' => 'Minor Theatre Procedure',
        'operating_clinician_user_id' => $operatingClinicianUserId,
        'anesthetist_user_id' => $anesthetistUserId,
        'theatre_room_service_point_id' => null,
        'theatre_room_name' => 'Procedure Room 1',
        'scheduled_at' => now()->addHour()->toDateTimeString(),
        'started_at' => null,
        'completed_at' => null,
        'status' => 'planned',
        'entry_state' => 'active',
        'signed_at' => null,
        'signed_by_user_id' => null,
        'status_reason' => null,
        'lifecycle_reason_code' => null,
        'entered_in_error_at' => null,
        'entered_in_error_by_user_id' => null,
        'lifecycle_locked_at' => null,
        'notes' => 'Prepared for theatre note linkage test.',
    ], $overrides));
}

function medicalRecordPayload(string $patientId, array $overrides = []): array
{
    return array_merge([
        'patientId' => $patientId,
        'encounterAt' => now()->toDateTimeString(),
        'recordType' => 'progress_note',
        'subjective' => 'Patient reports mild pain',
        'objective' => 'Vitals stable',
        'assessment' => 'Recovering well',
        'plan' => 'Continue observation for 24 hours',
        'diagnosisCode' => 'R52',
    ], $overrides);
}

function seedMedicalRecordDiagnosisCatalogCode(string $code, array $overrides = []): void
{
    ClinicalCatalogItemModel::query()->firstOrCreate(
        [
            'tenant_id' => null,
            'facility_id' => null,
            'catalog_type' => 'diagnosis_code',
            'code' => strtoupper(trim($code)),
        ],
        array_merge([
            'name' => 'Diagnosis '.strtoupper(trim($code)),
            'department_id' => null,
            'category' => 'general',
            'unit' => null,
            'description' => null,
            'metadata' => null,
            'status' => 'active',
            'status_reason' => null,
        ], $overrides),
    );
}

function grantMedicalRecordReadPermission(User $user): void
{
    $user->givePermissionTo('medical.records.read');
}

function grantMedicalRecordAuthorPermissions(User $user): void
{
    foreach ([
        'medical.records.read',
        'medical.records.create',
        'medical.records.update',
        'medical.records.finalize',
        'medical.records.amend',
        'medical.records.attest',
    ] as $permission) {
        $user->givePermissionTo($permission);
    }
}

function grantMedicalRecordArchivePermission(User $user): void
{
    $user->givePermissionTo('medical.records.archive');
}

function makeMedicalRecordReadOnlyUser(): User
{
    $user = User::factory()->create();
    grantMedicalRecordReadPermission($user);

    return $user;
}

function makeMedicalRecordUser(): User
{
    $user = User::factory()->create();
    grantMedicalRecordAuthorPermissions($user);

    return $user;
}

it('requires authentication for medical record creation', function (): void {
    $patient = makeMedicalRecordPatient();

    $this->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertUnauthorized();
});

it('forbids medical record list without read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records')
        ->assertForbidden();
});

it('forbids medical record show without read permission', function (): void {
    $userWithRead = makeMedicalRecordUser();
    $userWithoutRead = User::factory()->create();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($userWithRead)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/medical-records/'.$created['id'])
        ->assertForbidden();
});

it('forbids medical record creation without read permission', function (): void {
    $user = User::factory()->create();
    $patient = makeMedicalRecordPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertForbidden();
});

it('forbids medical record creation without create permission', function (): void {
    $user = makeMedicalRecordReadOnlyUser();
    $patient = makeMedicalRecordPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertForbidden();
});

it('can create medical record for existing patient', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);
    $admission = makeMedicalRecordAdmission($patient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
            'authorUserId' => $user->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.recordType', 'progress_note')
        ->assertJsonPath('data.status', 'draft');
});

it('creates governed admission referral nursing and procedure medical record note types', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);
    $admission = makeMedicalRecordAdmission($patient->id);
    $referral = makeMedicalRecordAppointmentReferral($appointment->id);
    $theatreProcedure = makeMedicalRecordTheatreProcedure($patient->id, [
        'appointment_id' => $appointment->id,
        'admission_id' => $admission->id,
        'procedure_type' => 'laparotomy',
        'procedure_name' => 'Exploratory Laparotomy',
    ]);

    $scenarios = [
        [
            'recordType' => 'admission_note',
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
        ],
        [
            'recordType' => 'referral_note',
            'appointmentId' => $appointment->id,
            'appointmentReferralId' => $referral->id,
        ],
        [
            'recordType' => 'nursing_note',
            'appointmentId' => $appointment->id,
        ],
        [
            'recordType' => 'procedure_note',
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
            'theatreProcedureId' => $theatreProcedure->id,
        ],
    ];

    foreach ($scenarios as $scenario) {
        $this->actingAs($user)
            ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, $scenario))
            ->assertCreated()
            ->assertJsonPath('data.recordType', $scenario['recordType'])
            ->assertJsonPath(
                'data.appointmentReferralId',
                $scenario['recordType'] === 'referral_note'
                    ? $referral->id
                    : null,
            )
            ->assertJsonPath(
                'data.theatreProcedureId',
                $scenario['recordType'] === 'procedure_note'
                    ? $theatreProcedure->id
                    : null,
            )
            ->assertJsonPath('data.status', 'draft');
    }
});

it('creates a referral note with a linked appointment referral record', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);
    $referral = makeMedicalRecordAppointmentReferral($appointment->id, [
        'priority' => 'urgent',
        'status' => 'accepted',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'recordType' => 'referral_note',
            'appointmentId' => $appointment->id,
            'appointmentReferralId' => $referral->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.recordType', 'referral_note')
        ->assertJsonPath('data.appointmentId', $appointment->id)
        ->assertJsonPath('data.appointmentReferralId', $referral->id);
});

it('rejects referral linkage when appointment referral context is missing the linked appointment', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);
    $referral = makeMedicalRecordAppointmentReferral($appointment->id);

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'recordType' => 'referral_note',
            'appointmentReferralId' => $referral->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.appointmentReferralId.0', 'Referral linkage requires the linked appointment context.');
});

it('rejects referral linkage on non-referral medical record note types', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);
    $referral = makeMedicalRecordAppointmentReferral($appointment->id);

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'recordType' => 'progress_note',
            'appointmentId' => $appointment->id,
            'appointmentReferralId' => $referral->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.appointmentReferralId.0', 'Only referral notes can link to a referral handoff record.');
});

it('creates a procedure note with a linked theatre procedure record', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);
    $admission = makeMedicalRecordAdmission($patient->id);
    $theatreProcedure = makeMedicalRecordTheatreProcedure($patient->id, [
        'appointment_id' => $appointment->id,
        'admission_id' => $admission->id,
        'procedure_type' => 'bronchoscopy',
        'procedure_name' => 'Diagnostic Bronchoscopy',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'recordType' => 'procedure_note',
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
            'theatreProcedureId' => $theatreProcedure->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.recordType', 'procedure_note')
        ->assertJsonPath('data.theatreProcedureId', $theatreProcedure->id);
});

it('rejects theatre procedure linkage on non-procedure medical record note types', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);
    $theatreProcedure = makeMedicalRecordTheatreProcedure($patient->id, [
        'appointment_id' => $appointment->id,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'recordType' => 'progress_note',
            'appointmentId' => $appointment->id,
            'theatreProcedureId' => $theatreProcedure->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.theatreProcedureId.0', 'Only procedure notes can link to a theatre procedure record.');
});

it('rejects theatre procedure linkage when encounter context does not match the selected appointment', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointmentA = makeMedicalRecordAppointment($patient->id);
    $appointmentB = makeMedicalRecordAppointment($patient->id);
    $theatreProcedure = makeMedicalRecordTheatreProcedure($patient->id, [
        'appointment_id' => $appointmentA->id,
        'procedure_type' => 'appendectomy',
        'procedure_name' => 'Appendectomy',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'recordType' => 'procedure_note',
            'appointmentId' => $appointmentB->id,
            'theatreProcedureId' => $theatreProcedure->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.theatreProcedureId.0', 'Theatre procedure is not aligned to the selected appointment.');
});

it('filters medical record list and status counts by admission context', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $admissionA = makeMedicalRecordAdmission($patient->id);
    $admissionB = makeMedicalRecordAdmission($patient->id);

    $recordA1 = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'admissionId' => $admissionA->id,
            'recordType' => 'admission_note',
        ]))
        ->assertCreated()
        ->json('data');

    $recordA2 = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'admissionId' => $admissionA->id,
            'recordType' => 'progress_note',
        ]))
        ->assertCreated()
        ->json('data');

    $recordB = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'admissionId' => $admissionB->id,
            'recordType' => 'nursing_note',
        ]))
        ->assertCreated()
        ->json('data');

    MedicalRecordModel::query()->whereKey($recordA1['id'])->update(['status' => 'finalized']);
    MedicalRecordModel::query()->whereKey($recordA2['id'])->update(['status' => 'draft']);
    MedicalRecordModel::query()->whereKey($recordB['id'])->update(['status' => 'amended']);

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records?patientId='.$patient->id.'&admissionId='.$admissionA->id)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.admissionId', $admissionA->id)
        ->assertJsonPath('data.1.admissionId', $admissionA->id);

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records/status-counts?patientId='.$patient->id.'&admissionId='.$admissionA->id)
        ->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.finalized', 1)
        ->assertJsonPath('data.draft', 1)
        ->assertJsonPath('data.amended', 0);
});

it('filters medical record list and status counts by appointment referral context', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);
    $referralA = makeMedicalRecordAppointmentReferral($appointment->id, [
        'target_department' => 'Respiratory',
    ]);
    $referralB = makeMedicalRecordAppointmentReferral($appointment->id, [
        'target_department' => 'Orthopaedics',
    ]);

    $recordA1 = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'appointmentReferralId' => $referralA->id,
            'recordType' => 'referral_note',
        ]))
        ->assertCreated()
        ->json('data');

    $recordA2 = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'appointmentReferralId' => $referralA->id,
            'recordType' => 'referral_note',
        ]))
        ->assertCreated()
        ->json('data');

    $recordB = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'appointmentReferralId' => $referralB->id,
            'recordType' => 'referral_note',
        ]))
        ->assertCreated()
        ->json('data');

    MedicalRecordModel::query()->whereKey($recordA1['id'])->update(['status' => 'draft']);
    MedicalRecordModel::query()->whereKey($recordA2['id'])->update(['status' => 'finalized']);
    MedicalRecordModel::query()->whereKey($recordB['id'])->update(['status' => 'amended']);

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records?patientId='.$patient->id.'&appointmentReferralId='.$referralA->id.'&recordType=referral_note')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.appointmentReferralId', $referralA->id)
        ->assertJsonPath('data.1.appointmentReferralId', $referralA->id);

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records/status-counts?patientId='.$patient->id.'&appointmentReferralId='.$referralA->id.'&recordType=referral_note')
        ->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.draft', 1)
        ->assertJsonPath('data.finalized', 1)
        ->assertJsonPath('data.amended', 0);
});

it('filters medical record list and status counts by theatre procedure context', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);
    $admission = makeMedicalRecordAdmission($patient->id);
    $theatreProcedureA = makeMedicalRecordTheatreProcedure($patient->id, [
        'appointment_id' => $appointment->id,
        'admission_id' => $admission->id,
        'procedure_type' => 'laparoscopy',
        'procedure_name' => 'Diagnostic Laparoscopy',
    ]);
    $theatreProcedureB = makeMedicalRecordTheatreProcedure($patient->id, [
        'appointment_id' => $appointment->id,
        'admission_id' => $admission->id,
        'procedure_type' => 'thoracotomy',
        'procedure_name' => 'Thoracotomy',
    ]);

    $recordA1 = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
            'recordType' => 'procedure_note',
            'theatreProcedureId' => $theatreProcedureA->id,
        ]))
        ->assertCreated()
        ->json('data');

    $recordA2 = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
            'recordType' => 'procedure_note',
            'theatreProcedureId' => $theatreProcedureA->id,
        ]))
        ->assertCreated()
        ->json('data');

    $recordB = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
            'recordType' => 'procedure_note',
            'theatreProcedureId' => $theatreProcedureB->id,
        ]))
        ->assertCreated()
        ->json('data');

    MedicalRecordModel::query()->whereKey($recordA1['id'])->update(['status' => 'finalized']);
    MedicalRecordModel::query()->whereKey($recordA2['id'])->update(['status' => 'draft']);
    MedicalRecordModel::query()->whereKey($recordB['id'])->update(['status' => 'amended']);

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records?patientId='.$patient->id.'&theatreProcedureId='.$theatreProcedureA->id)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.theatreProcedureId', $theatreProcedureA->id)
        ->assertJsonPath('data.1.theatreProcedureId', $theatreProcedureA->id);

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records/status-counts?patientId='.$patient->id.'&theatreProcedureId='.$theatreProcedureA->id)
        ->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.finalized', 1)
        ->assertJsonPath('data.draft', 1)
        ->assertJsonPath('data.amended', 0);
});

it('forbids draft medical record updates without update permission', function (): void {
    $author = makeMedicalRecordUser();
    $readOnlyUser = makeMedicalRecordReadOnlyUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($author)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($readOnlyUser)
        ->patchJson('/api/v1/medical-records/'.$created['id'], [
            'assessment' => 'Attempted unauthorized update',
        ])
        ->assertForbidden();
});

it('allows draft author autosave updates with create permission', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $author = makeMedicalRecordUser();
    $updatePermissionId = DB::table('permissions')
        ->where('name', 'medical.records.update')
        ->value('id');
    DB::table('permission_user')
        ->where('user_id', $author->id)
        ->where('permission_id', $updatePermissionId)
        ->delete();
    $patient = makeMedicalRecordPatient();
    [$tenantId, $facilityId] = seedMedicalRecordPlatformScopeAssignment(
        userId: $author->id,
        tenantCode: 'ENC',
        tenantName: 'Encounter Autosave Network',
        countryCode: 'TZ',
        facilityCode: 'ENC-MR',
        facilityName: 'Encounter Medical Records',
    );

    $draft = MedicalRecordModel::query()->create([
        'record_number' => 'MR'.now()->format('Ymd').strtoupper(Str::random(6)),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'author_user_id' => $author->id,
        'encounter_at' => now()->toDateTimeString(),
        'record_type' => 'consultation_note',
        'subjective' => null,
        'objective' => null,
        'assessment' => null,
        'plan' => null,
        'status' => 'draft',
    ]);

    $this->actingAs($author)
        ->withHeaders([
            'X-Tenant-Code' => 'ENC',
            'X-Facility-Code' => 'ENC-MR',
        ])
        ->patchJson('/api/v1/medical-records/'.$draft->id, [
            'subjective' => 'Autosaved encounter note text',
            'assessment' => 'Draft should resume with text',
        ])
        ->assertOk()
        ->assertJsonPath('data.subjective', 'Autosaved encounter note text')
        ->assertJsonPath('data.assessment', 'Draft should resume with text');
});

it('returns draft conflict when expectedUpdatedAt is stale', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    [$tenantId, $facilityId] = seedMedicalRecordPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'DRF',
        tenantName: 'Draft Conflict Network',
        countryCode: 'TZ',
        facilityCode: 'DRF-MR',
        facilityName: 'Draft Conflict Medical Records',
    );

    $draft = MedicalRecordModel::query()->create([
        'record_number' => 'MR'.now()->format('Ymd').strtoupper(Str::random(6)),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'author_user_id' => $user->id,
        'encounter_at' => now()->toDateTimeString(),
        'record_type' => 'consultation_note',
        'assessment' => 'Initial draft copy',
        'status' => 'draft',
    ]);

    $staleUpdatedAt = now()->subMinutes(5)->toISOString();

    MedicalRecordModel::query()
        ->whereKey($draft->id)
        ->update([
            'assessment' => 'Another tab saved newer copy',
            'updated_at' => now(),
        ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'DRF',
            'X-Facility-Code' => 'DRF-MR',
        ])
        ->patchJson('/api/v1/medical-records/'.$draft->id, [
            'assessment' => 'Stale autosave attempt',
            'expectedUpdatedAt' => $staleUpdatedAt,
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'MEDICAL_RECORD_DRAFT_CONFLICT')
        ->assertJsonPath('context.currentRecord.assessment', 'Another tab saved newer copy');
});

it('allows force draft save to overwrite stale chart copy', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    [$tenantId, $facilityId] = seedMedicalRecordPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'FRC',
        tenantName: 'Force Draft Save Network',
        countryCode: 'TZ',
        facilityCode: 'FRC-MR',
        facilityName: 'Force Draft Save Medical Records',
    );

    $draft = MedicalRecordModel::query()->create([
        'record_number' => 'MR'.now()->format('Ymd').strtoupper(Str::random(6)),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'author_user_id' => $user->id,
        'encounter_at' => now()->toDateTimeString(),
        'record_type' => 'consultation_note',
        'assessment' => 'Initial draft copy',
        'status' => 'draft',
    ]);

    $staleUpdatedAt = $draft->fresh()?->updated_at?->toISOString();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'FRC',
            'X-Facility-Code' => 'FRC-MR',
        ])
        ->patchJson('/api/v1/medical-records/'.$draft->id, [
            'assessment' => 'Current chart copy',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'FRC',
            'X-Facility-Code' => 'FRC-MR',
        ])
        ->patchJson('/api/v1/medical-records/'.$draft->id, [
            'assessment' => 'Forced overwrite from this screen',
            'expectedUpdatedAt' => $staleUpdatedAt,
            'forceDraftSave' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.assessment', 'Forced overwrite from this screen');
});

it('rejects invalid medical record status transitions', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'amended',
            'reason' => 'Too early',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('opens an amendment session by reopening a finalized note as draft', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'finalized',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'finalized');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'amended',
            'reason' => 'Correction required',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.statusReason', 'Correction required');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'], [
            'assessment' => 'Corrected assessment',
        ])
        ->assertOk()
        ->assertJsonPath('data.assessment', 'Corrected assessment');
});

it('finalizes an amendment session into amended status', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    seedMedicalRecordPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'AMD',
        tenantName: 'Amendment Finalize Network',
        countryCode: 'TZ',
        facilityCode: 'AMD-MR',
        facilityName: 'Amendment Medical Records',
    );

    $created = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'AMD',
            'X-Facility-Code' => 'AMD-MR',
        ])
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'AMD',
            'X-Facility-Code' => 'AMD-MR',
        ])
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'finalized',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'AMD',
            'X-Facility-Code' => 'AMD-MR',
        ])
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'amended',
            'reason' => 'Correction required',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'AMD',
            'X-Facility-Code' => 'AMD-MR',
        ])
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'finalized',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'amended');
});

it('syncs encounter lifecycle when consultation note is finalized and closed', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
        ]))
        ->assertCreated()
        ->json('data');

    expect($created['encounterId'])->not->toBeNull();

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'finalized',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/encounters/'.$created['encounterId'])
        ->assertOk()
        ->assertJsonPath('data.status', 'signed');

    $this->actingAs($user)
        ->patchJson('/api/v1/encounters/'.$created['encounterId'].'/status', [
            'status' => 'closed',
            'acknowledgeCloseGaps' => true,
            'reason' => 'Visit complete',
            'disposition' => 'discharged',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.statusReason', 'Visit complete');
});

it('creates an encounter identity for appointment medical record drafts', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.appointmentId', $appointment->id)
        ->json('data');

    expect($created['encounterId'])->not->toBeNull();

    $encounter = EncounterModel::query()->findOrFail($created['encounterId']);
    expect($encounter->patient_id)->toBe($patient->id);
    expect($encounter->appointment_id)->toBe($appointment->id);
    expect($encounter->status)->toBe('opened');
});

it('resolves an appointment encounter and returns a workspace bundle', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
            'assessment' => 'Workspace bundle seed note',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/'.$appointment->id.'/encounter')
        ->assertOk()
        ->assertJsonPath('data.appointmentId', $appointment->id)
        ->assertJsonPath('data.patientId', $patient->id);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments/'.$appointment->id.'/encounter?view=workspace')
        ->assertOk()
        ->assertJsonPath('data.encounter.id', $created['encounterId'])
        ->assertJsonPath('data.appointment.id', $appointment->id)
        ->assertJsonPath('data.primaryMedicalRecord.id', $created['id'])
        ->assertJsonPath('data.primaryMedicalRecord.assessment', 'Workspace bundle seed note');

    $this->actingAs($user)
        ->getJson('/api/v1/encounters/'.$created['encounterId'].'?view=workspace')
        ->assertOk()
        ->assertJsonPath('data.encounter.id', $created['encounterId'])
        ->assertJsonPath('data.primaryMedicalRecord.id', $created['id']);
});

it('loads encounter workspace theatre procedures without invalid timestamp filters', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
            'assessment' => 'Workspace theatre linkage note',
        ]))
        ->assertCreated()
        ->json('data');

    makeMedicalRecordTheatreProcedure($patient->id, [
        'encounter_id' => $created['encounterId'],
        'appointment_id' => $appointment->id,
        'entered_in_error_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/encounters/'.$created['encounterId'].'?view=workspace')
        ->assertOk()
        ->assertJsonPath('data.encounter.id', $created['encounterId'])
        ->assertJsonCount(1, 'data.theatreProcedures');
});

it('returns encounter close readiness in the workspace bundle', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
            'assessment' => 'Acute upper respiratory infection',
            'diagnosisCode' => 'J06.9',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/encounters/'.$created['encounterId'].'?view=workspace')
        ->assertOk()
        ->assertJsonPath('data.closeReadiness.items.0.id', 'note_signed')
        ->assertJsonPath('data.closeReadiness.items.0.status', 'fail')
        ->assertJsonPath('data.closeReadiness.billingSummary.pendingCandidates', 1);
});

it('blocks encounter close when the consultation note is unsigned', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/encounters/'.$created['encounterId'].'/status', [
            'status' => 'closed',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ENCOUNTER_CLOSE_BLOCKED')
        ->assertJsonPath('data.closeReadiness.items.0.id', 'note_signed')
        ->assertJsonPath('data.closeReadiness.items.0.status', 'fail');
});

it('requires acknowledgement when closing with documentation or billing warnings', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);
    $catalogItem = ClinicalCatalogItemModel::query()->firstOrCreate(
        [
            'tenant_id' => null,
            'facility_id' => null,
            'catalog_type' => 'lab_test',
            'code' => 'LOINC:58410-2',
        ],
        [
            'name' => 'Basic metabolic panel',
            'department_id' => null,
            'category' => 'chemistry',
            'unit' => null,
            'description' => 'Close readiness lab fixture',
            'metadata' => null,
            'status' => 'active',
            'status_reason' => null,
        ],
    );

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
            'assessment' => '',
            'diagnosisCode' => null,
        ]))
        ->assertCreated()
        ->json('data');

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'encounter_id' => $created['encounterId'],
        'appointment_id' => $appointment->id,
        'lab_test_catalog_item_id' => $catalogItem->id,
        'test_code' => $catalogItem->code,
        'test_name' => $catalogItem->name,
        'priority' => 'routine',
        'ordered_at' => now(),
        'status' => 'ordered',
        'entry_state' => 'active',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'finalized',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/encounters/'.$created['encounterId'].'/status', [
            'status' => 'closed',
            'disposition' => 'discharged',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ENCOUNTER_CLOSE_BLOCKED')
        ->assertJsonPath('data.closeReadiness.requiresAcknowledgement', true);

    $this->actingAs($user)
        ->patchJson('/api/v1/encounters/'.$created['encounterId'].'/status', [
            'status' => 'closed',
            'acknowledgeCloseGaps' => true,
            'reason' => 'Patient left before billing capture completed',
            'disposition' => 'discharged',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.statusReason', 'Patient left before billing capture completed');
});

it('writes encounter audit logs for open close and reopen lifecycle events', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
        ]))
        ->assertCreated()
        ->json('data');

    $encounterId = $created['encounterId'];
    expect($encounterId)->not->toBeNull();

    expect(
        EncounterAuditLogModel::query()
            ->where('encounter_id', $encounterId)
            ->where('action', 'encounter.opened')
            ->exists(),
    )->toBeTrue();

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'finalized',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/encounters/'.$encounterId.'/status', [
            'status' => 'closed',
            'acknowledgeCloseGaps' => true,
            'reason' => 'Visit complete',
            'disposition' => 'discharged',
        ])
        ->assertOk();

    expect(
        EncounterAuditLogModel::query()
            ->where('encounter_id', $encounterId)
            ->where('action', 'encounter.closed')
            ->exists(),
    )->toBeTrue();

    $this->actingAs($user)
        ->patchJson('/api/v1/encounters/'.$encounterId.'/status', [
            'status' => 'reopened',
            'reason' => 'Billing correction required',
        ])
        ->assertOk();

    expect(
        EncounterAuditLogModel::query()
            ->where('encounter_id', $encounterId)
            ->where('action', 'encounter.reopened')
            ->exists(),
    )->toBeTrue();
});

it('lists encounter audit logs when authorized', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $user->givePermissionTo('medical-records.view-audit-logs');
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/encounters/'.$created['encounterId'].'/audit-logs?perPage=5')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                [
                    'id',
                    'encounterId',
                    'action',
                    'actionLabel',
                    'createdAt',
                ],
            ],
            'meta' => [
                'currentPage',
                'lastPage',
                'perPage',
                'total',
            ],
        ])
        ->assertJsonPath('data.0.action', 'encounter.opened');
});

it('forbids encounter audit log access without permission', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $author = makeMedicalRecordUser();
    $readOnlyUser = makeMedicalRecordReadOnlyUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);

    $created = $this->actingAs($author)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($readOnlyUser)
        ->getJson('/api/v1/encounters/'.$created['encounterId'].'/audit-logs')
        ->assertForbidden();
});

it('forbids medical record finalization without finalize permission', function (): void {
    $author = makeMedicalRecordUser();
    $readOnlyUser = makeMedicalRecordReadOnlyUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($author)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($readOnlyUser)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'finalized',
        ])
        ->assertForbidden();
});

it('forbids medical record archiving without archive permission', function (): void {
    $author = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($author)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($author)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'archived',
            'reason' => 'Attempted unauthorized archive',
        ])
        ->assertForbidden();
});

it('forbids signer attestation without attestation permission', function (): void {
    $author = makeMedicalRecordUser();
    $readOnlyUser = makeMedicalRecordReadOnlyUser();
    $patient = makeMedicalRecordPatient();

    $record = $this->actingAs($author)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($author)
        ->patchJson('/api/v1/medical-records/'.$record['id'].'/status', [
            'status' => 'finalized',
        ])
        ->assertOk();

    $this->actingAs($readOnlyUser)
        ->postJson('/api/v1/medical-records/'.$record['id'].'/signer-attestations', [
            'attestationNote' => 'Attempted unauthorized attestation',
        ])
        ->assertForbidden();
});

it('blocks consultation note create when another clinician owns active consultation session', function (): void {
    $owner = makeMedicalRecordUser();
    $otherClinician = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(8)->toDateTimeString(),
        'consultation_owner_user_id' => $owner->id,
        'consultation_owner_assigned_at' => now()->subMinutes(8)->toDateTimeString(),
    ]);

    $this->actingAs($otherClinician)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
        ]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONSULTATION_OWNER_CONFLICT')
        ->assertJsonPath('context.consultationOwnerUserId', $owner->id);
});

it('allows consultation note create for the active consultation owner', function (): void {
    $owner = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(5)->toDateTimeString(),
        'consultation_owner_user_id' => $owner->id,
        'consultation_owner_assigned_at' => now()->subMinutes(5)->toDateTimeString(),
    ]);

    $this->actingAs($owner)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.appointmentId', $appointment->id)
        ->assertJsonPath('data.recordType', 'consultation_note');
});

it('blocks consultation note create when legacy active consultation only has assigned clinician ownership', function (): void {
    $assignedClinician = makeMedicalRecordUser();
    $otherClinician = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id, [
        'clinician_user_id' => $assignedClinician->id,
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(5)->toDateTimeString(),
        'consultation_owner_user_id' => null,
        'consultation_owner_assigned_at' => null,
    ]);

    $this->actingAs($otherClinician)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
        ]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONSULTATION_OWNER_CONFLICT')
        ->assertJsonPath('context.consultationOwnerUserId', $assignedClinician->id);
});

it('blocks consultation note update when another clinician owns active consultation session', function (): void {
    $owner = makeMedicalRecordUser();
    $otherClinician = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(6)->toDateTimeString(),
        'consultation_owner_user_id' => $owner->id,
        'consultation_owner_assigned_at' => now()->subMinutes(6)->toDateTimeString(),
    ]);

    $record = $this->actingAs($owner)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($otherClinician)
        ->patchJson('/api/v1/medical-records/'.$record['id'], [
            'assessment' => 'Edited by another clinician',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONSULTATION_OWNER_CONFLICT')
        ->assertJsonPath('context.consultationOwnerUserId', $owner->id);
});

it('blocks consultation note status update when another clinician owns active consultation session', function (): void {
    $owner = makeMedicalRecordUser();
    $otherClinician = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id, [
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(4)->toDateTimeString(),
        'consultation_owner_user_id' => $owner->id,
        'consultation_owner_assigned_at' => now()->subMinutes(4)->toDateTimeString(),
    ]);

    $record = $this->actingAs($owner)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'recordType' => 'consultation_note',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($otherClinician)
        ->patchJson('/api/v1/medical-records/'.$record['id'].'/status', [
            'status' => 'finalized',
            'reason' => null,
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONSULTATION_OWNER_CONFLICT')
        ->assertJsonPath('context.consultationOwnerUserId', $owner->id);
});

it('rejects medical record for missing patient', function (): void {
    $user = makeMedicalRecordUser();

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload((string) Str::uuid()))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['patientId']);
});

it('rejects medical record create when diagnosis code format is invalid', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'diagnosisCode' => 'invalid-code',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['diagnosisCode']);
});

it('rejects medical record create when record type is outside the governed note catalog', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'recordType' => 'ward_round_note',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['recordType']);
});

it('normalizes diagnosis code to uppercase during medical record create', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    seedMedicalRecordDiagnosisCatalogCode('J11.1');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'diagnosisCode' => 'j11.1',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.diagnosisCode', 'J11.1')
        ->json('data');

    $stored = MedicalRecordModel::query()->findOrFail($created['id']);
    expect($stored->diagnosis_code)->toBe('J11.1');
});

it('rejects medical record create when diagnosis code is not active in terminology catalog once configured', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    seedMedicalRecordDiagnosisCatalogCode('R52');

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'diagnosisCode' => 'Z09',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['diagnosisCode']);
});

it('rejects medical record update when diagnosis code is not active in terminology catalog once configured', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    seedMedicalRecordDiagnosisCatalogCode('R52');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'diagnosisCode' => 'R52',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'], [
            'diagnosisCode' => 'Z09',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['diagnosisCode']);
});

it('locks direct content edits after medical record finalization', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'finalized',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'], [
            'assessment' => 'Attempted edit after finalization',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payload']);
});

it('rejects medical record when appointment does not belong to patient', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $otherPatient = makeMedicalRecordPatient([
        'phone' => '+255788889999',
        'first_name' => 'Other',
        'last_name' => 'Patient',
    ]);
    $appointment = makeMedicalRecordAppointment($otherPatient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['appointmentId']);
});

it('rejects medical record when admission does not belong to patient', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $otherPatient = makeMedicalRecordPatient([
        'phone' => '+255766667777',
        'first_name' => 'Third',
        'last_name' => 'Patient',
    ]);
    $admission = makeMedicalRecordAdmission($otherPatient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'admissionId' => $admission->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['admissionId']);
});

it('fetches medical record by id', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.id', $created['id']);
});

it('updates medical record fields', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'], [
            'recordType' => 'nursing_note',
            'assessment' => 'Condition improving',
            'plan' => 'Discharge review tomorrow',
        ])
        ->assertOk()
        ->assertJsonPath('data.recordType', 'nursing_note')
        ->assertJsonPath('data.assessment', 'Condition improving')
        ->assertJsonPath('data.plan', 'Discharge review tomorrow');
});

it('updates a draft medical record into a procedure note with linked theatre context', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    $appointment = makeMedicalRecordAppointment($patient->id);
    $admission = makeMedicalRecordAdmission($patient->id);
    $theatreProcedure = makeMedicalRecordTheatreProcedure($patient->id, [
        'appointment_id' => $appointment->id,
        'admission_id' => $admission->id,
        'procedure_type' => 'endoscopy',
        'procedure_name' => 'Upper GI Endoscopy',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'], [
            'recordType' => 'procedure_note',
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
            'theatreProcedureId' => $theatreProcedure->id,
            'objective' => 'Procedure completed with no immediate complication.',
        ])
        ->assertOk()
        ->assertJsonPath('data.recordType', 'procedure_note')
        ->assertJsonPath('data.theatreProcedureId', $theatreProcedure->id)
        ->assertJsonPath('data.objective', 'Procedure completed with no immediate complication.');
});

it('rejects medical record update when record type is outside the governed note catalog', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'], [
            'recordType' => 'ward_round_note',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['recordType']);
});

it('rejects medical record update when diagnosis code format is invalid', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'], [
            'diagnosisCode' => '1234',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['diagnosisCode']);
});

it('rejects status lifecycle fields on medical record detail update endpoint', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'], [
            'assessment' => 'Should not persist',
            'status' => 'archived',
            'reason' => 'Lifecycle update attempt',
            'signedByUserId' => $user->id,
            'signedAt' => now()->toDateTimeString(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status', 'reason', 'signedByUserId', 'signedAt']);

    $record = MedicalRecordModel::query()->findOrFail($created['id']);
    expect($record->assessment)->toBe('Recovering well');
    expect($record->status)->toBe('draft');
    expect($record->signed_by_user_id)->toBeNull();
    expect($record->signed_at)->toBeNull();
});

it('updates medical record status', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'finalized',
            'reason' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'finalized')
        ->assertJsonPath('data.signedByUserId', $user->id);

    $signed = MedicalRecordModel::query()->findOrFail($created['id']);
    expect($signed->signed_by_user_id)->toBe($user->id);
    expect($signed->signed_at)->not->toBeNull();
});

it('enforces reason for archived status and writes transition metadata', function (): void {
    $user = makeMedicalRecordUser();
    grantMedicalRecordArchivePermission($user);
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'archived',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
            'status' => 'archived',
            'reason' => 'Retention policy',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'archived')
        ->assertJsonPath('data.statusReason', 'Retention policy');

    $statusLog = MedicalRecordAuditLogModel::query()
        ->where('medical_record_id', $created['id'])
        ->where('action', 'medical-record.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('draft');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('archived');
    expect($statusLog?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('writes medical record audit logs for create update and status change', function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();
    seedMedicalRecordPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'AUD',
        tenantName: 'Audit Lifecycle Network',
        countryCode: 'TZ',
        facilityCode: 'AUD-MR',
        facilityName: 'Audit Medical Records',
    );

    $created = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'AUD',
            'X-Facility-Code' => 'AUD-MR',
        ])
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'AUD',
            'X-Facility-Code' => 'AUD-MR',
        ])
        ->patchJson('/api/v1/medical-records/'.$created['id'], [
        'assessment' => 'audit log update check',
    ])->assertOk();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'AUD',
            'X-Facility-Code' => 'AUD-MR',
        ])
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
        'status' => 'finalized',
        'reason' => null,
    ])->assertOk();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'AUD',
            'X-Facility-Code' => 'AUD-MR',
        ])
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
        'status' => 'amended',
        'reason' => 'correction',
    ])->assertOk();

    $logs = MedicalRecordAuditLogModel::query()
        ->where('medical_record_id', $created['id'])
        ->orderBy('created_at')
        ->get();

    expect($logs)->toHaveCount(4);
    expect($logs->pluck('action')->all())->toContain(
        'medical-record.created',
        'medical-record.updated',
        'medical-record.status.updated',
    );
    expect($logs->first()->actor_id)->toBe($user->id);

    $createdLog = $logs->firstWhere('action', 'medical-record.created');
    $updatedLog = $logs->firstWhere('action', 'medical-record.updated');
    $statusLog = $logs->firstWhere('action', 'medical-record.status.updated');

    expect($createdLog)->not->toBeNull();
    expect($updatedLog)->not->toBeNull();
    expect($statusLog)->not->toBeNull();
});

it('lists medical record audit logs when authorized', function (): void {
    $user = makeMedicalRecordUser();
    $user->givePermissionTo('medical-records.view-audit-logs');
    grantMedicalRecordArchivePermission($user);
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/medical-records/'.$created['id'], [
        'assessment' => 'list audit logs check',
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/medical-records/'.$created['id'].'/status', [
        'status' => 'archived',
        'reason' => 'retention policy',
    ])->assertOk();

    MedicalRecordAuditLogModel::query()->create([
        'medical_record_id' => $created['id'],
        'action' => 'medical-record.document.pdf.downloaded',
        'actor_id' => $user->id,
        'changes' => [],
        'metadata' => [
            'document_filename' => 'afyanova_record.pdf',
            'request_ip' => '203.0.113.12',
        ],
        'created_at' => now()->addSecond(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records/'.$created['id'].'/audit-logs?perPage=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('data.0.action', 'medical-record.document.pdf.downloaded')
        ->assertJsonPath('data.0.actionLabel', 'PDF Downloaded')
        ->assertJsonPath('data.0.actor.id', $user->id)
        ->assertJsonPath('data.1.action', 'medical-record.status.updated');
});

it('forbids medical record audit log access without permission', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('forbids medical record audit logs when gate override denies', function (): void {
    Gate::define('medical-records.view-audit-logs', static fn (): bool => false);

    $user = makeMedicalRecordUser();
    $user->givePermissionTo('medical-records.view-audit-logs');
    $patient = makeMedicalRecordPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('returns 404 for medical record audit logs of unknown id', function (): void {
    $user = makeMedicalRecordUser();
    $user->givePermissionTo('medical-records.view-audit-logs');

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records/060afc03-2ce9-4b1d-a1c2-326d2722ce25/audit-logs')
        ->assertNotFound();
});

it('lists medical record versions and returns diffs across versions', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $record = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$record['id'], [
            'assessment' => 'Version two assessment',
            'plan' => 'Version two plan',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$record['id'].'/status', [
            'status' => 'finalized',
        ])
        ->assertOk();

    $versions = $this->actingAs($user)
        ->getJson('/api/v1/medical-records/'.$record['id'].'/versions?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.versionNumber', 3)
        ->assertJsonPath('data.1.versionNumber', 2)
        ->assertJsonPath('data.2.versionNumber', 1)
        ->json('data');

    $versionThreeId = $versions[0]['id'];
    $versionOneId = $versions[2]['id'];

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records/'.$record['id'].'/versions/'.$versionThreeId.'/diff')
        ->assertOk()
        ->assertJsonPath('data.targetVersion.versionNumber', 3)
        ->assertJsonPath('data.baseVersion.versionNumber', 2);

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records/'.$record['id'].'/versions/'.$versionThreeId.'/diff?againstVersionId='.$versionOneId)
        ->assertOk()
        ->assertJsonPath('data.targetVersion.versionNumber', 3)
        ->assertJsonPath('data.baseVersion.versionNumber', 1);

    expect(MedicalRecordVersionModel::query()->where('medical_record_id', $record['id'])->count())->toBe(3);
});

it('rejects medical record version diff comparison when against version belongs to another record', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $firstRecord = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$firstRecord['id'], [
            'assessment' => 'First record changed',
        ])
        ->assertOk();

    $secondRecord = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'recordType' => 'discharge_note',
        ]))
        ->assertCreated()
        ->json('data');

    $firstRecordVersion = MedicalRecordVersionModel::query()
        ->where('medical_record_id', $firstRecord['id'])
        ->orderByDesc('version_number')
        ->firstOrFail();

    $otherVersion = MedicalRecordVersionModel::query()
        ->where('medical_record_id', $secondRecord['id'])
        ->orderByDesc('version_number')
        ->firstOrFail();

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records/'.$firstRecord['id'].'/versions/'.$firstRecordVersion->id.'/diff?againstVersionId='.$otherVersion->id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['againstVersionId']);
});

it('enforces finalized or amended status before creating signer attestation', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $record = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records/'.$record['id'].'/signer-attestations', [
            'attestationNote' => 'Attempt before finalization',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attestationNote']);

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$record['id'].'/status', [
            'status' => 'finalized',
        ])
        ->assertOk();

    $createdAttestation = $this->actingAs($user)
        ->postJson('/api/v1/medical-records/'.$record['id'].'/signer-attestations', [
            'attestationNote' => 'Reviewed and attested by clinician',
        ])
        ->assertCreated()
        ->assertJsonPath('data.medicalRecordId', $record['id'])
        ->assertJsonPath('data.attestedByUserId', $user->id)
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records/'.$record['id'].'/signer-attestations')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $createdAttestation['id']);

    expect(MedicalRecordSignerAttestationModel::query()->where('medical_record_id', $record['id'])->count())->toBe(1);

    $auditLog = MedicalRecordAuditLogModel::query()
        ->where('medical_record_id', $record['id'])
        ->where('action', 'medical-record.signer-attested')
        ->latest('created_at')
        ->first();

    expect($auditLog)->not->toBeNull();
});

it('lists and filters medical records', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    MedicalRecordModel::query()->create([
        'record_number' => 'MR20260225AAAAAA',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'author_user_id' => null,
        'encounter_at' => now()->subHours(2)->toDateTimeString(),
        'record_type' => 'progress_note',
        'subjective' => null,
        'objective' => null,
        'assessment' => 'Stable progress',
        'plan' => 'Continue care',
        'diagnosis_code' => 'R52',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    MedicalRecordModel::query()->create([
        'record_number' => 'MR20260225BBBBBB',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'author_user_id' => null,
        'encounter_at' => now()->subHours(5)->toDateTimeString(),
        'record_type' => 'discharge_note',
        'subjective' => null,
        'objective' => null,
        'assessment' => 'Discharge completed',
        'plan' => 'Home care',
        'diagnosis_code' => 'Z09',
        'status' => 'finalized',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records?recordType=progress_note&status=draft')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.recordType', 'progress_note')
        ->assertJsonPath('data.0.status', 'draft');
});

it('does not crash when medical record patientId filter is not a uuid', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id, [
            'assessment' => 'Asha review note',
            'plan' => 'Follow-up in one week',
        ]))
        ->assertCreated();

    $this->actingAs($user)
        ->getJson('/api/v1/medical-records?q=Asha&patientId=PAT-DEMO-0001')
        ->assertOk();
});

it('stamps medical record tenant and facility scope when created under resolved platform scope', function (): void {
    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    [$tenantId, $facilityId] = seedMedicalRecordPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'TZC',
        tenantName: 'Tanzania Clinical Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-MR',
        facilityName: 'Dar Medical Records',
    );

    $created = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZC',
            'X-Facility-Code' => 'DAR-MR',
        ])
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $row = MedicalRecordModel::query()->findOrFail($created['id']);

    expect($row->tenant_id)->toBe($tenantId);
    expect($row->facility_id)->toBe($facilityId);
});

it('filters medical record reads by facility scope when platform multi facility scoping is enabled', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', true);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    [$tenantId, $facilityId] = seedMedicalRecordPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-MR',
        facilityName: 'Nairobi Medical Records',
    );

    [, $otherFacilityId] = seedMedicalRecordPlatformScopeFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-MR',
        facilityName: 'Mombasa Medical Records',
    );

    $visible = MedicalRecordModel::query()->create([
        'record_number' => 'MR20260225SCOPM1',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'author_user_id' => null,
        'encounter_at' => now()->subHour()->toDateTimeString(),
        'record_type' => 'progress_note',
        'subjective' => 'Scoped visible subjective',
        'objective' => 'Scoped visible objective',
        'assessment' => 'Scoped visible assessment',
        'plan' => 'Scoped visible plan',
        'diagnosis_code' => 'R52',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    $hidden = MedicalRecordModel::query()->create([
        'record_number' => 'MR20260225SCOPM2',
        'tenant_id' => $tenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'author_user_id' => null,
        'encounter_at' => now()->subHours(2)->toDateTimeString(),
        'record_type' => 'progress_note',
        'subjective' => 'Scoped hidden subjective',
        'objective' => 'Scoped hidden objective',
        'assessment' => 'Scoped hidden assessment',
        'plan' => 'Scoped hidden plan',
        'diagnosis_code' => 'Z09',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-MR',
        ])
        ->getJson('/api/v1/medical-records')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.assessment', 'Scoped visible assessment');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-MR',
        ])
        ->getJson('/api/v1/medical-records/'.$hidden->id)
        ->assertNotFound();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-MR',
        ])
        ->patchJson('/api/v1/medical-records/'.$hidden->id, [
            'assessment' => 'Attempted cross-facility update',
        ])
        ->assertNotFound();
});

it('filters medical record reads by facility scope when enabled via feature flag override', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    [$tenantId, $facilityId] = seedMedicalRecordPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-MR',
        facilityName: 'Nairobi Medical Records',
    );

    [, $otherFacilityId] = seedMedicalRecordPlatformScopeFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-MR',
        facilityName: 'Mombasa Medical Records',
    );

    DB::table('feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_name' => 'platform.multi_facility_scoping',
        'scope_type' => 'country',
        'scope_key' => 'KE',
        'enabled' => true,
        'reason' => 'enable scoping for Kenya medical record rollout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $visible = MedicalRecordModel::query()->create([
        'record_number' => 'MR20260225SCOPM3',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'author_user_id' => null,
        'encounter_at' => now()->subHour()->toDateTimeString(),
        'record_type' => 'progress_note',
        'subjective' => 'Override visible subjective',
        'objective' => 'Override visible objective',
        'assessment' => 'Override visible assessment',
        'plan' => 'Override visible plan',
        'diagnosis_code' => 'R52',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    MedicalRecordModel::query()->create([
        'record_number' => 'MR20260225SCOPM4',
        'tenant_id' => $tenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'author_user_id' => null,
        'encounter_at' => now()->subHours(2)->toDateTimeString(),
        'record_type' => 'progress_note',
        'subjective' => 'Override hidden subjective',
        'objective' => 'Override hidden objective',
        'assessment' => 'Override hidden assessment',
        'plan' => 'Override hidden plan',
        'diagnosis_code' => 'Z09',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-MR',
        ])
        ->getJson('/api/v1/medical-records')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.assessment', 'Override visible assessment');
});

it('blocks medical record creation in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', medicalRecordPayload($patient->id))
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks medical record update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $record = MedicalRecordModel::query()->create([
        'record_number' => 'MR20260225GUARDM1',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'author_user_id' => null,
        'encounter_at' => now()->toDateTimeString(),
        'record_type' => 'progress_note',
        'subjective' => 'Guard update subjective',
        'objective' => 'Guard update objective',
        'assessment' => 'Guard update assessment',
        'plan' => 'Guard update plan',
        'diagnosis_code' => 'R52',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$record->id, [
            'assessment' => 'Attempted guarded update',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks medical record status update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeMedicalRecordUser();
    $patient = makeMedicalRecordPatient();

    $record = MedicalRecordModel::query()->create([
        'record_number' => 'MR20260225GUARDM2',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'author_user_id' => null,
        'encounter_at' => now()->toDateTimeString(),
        'record_type' => 'progress_note',
        'subjective' => 'Guard status subjective',
        'objective' => 'Guard status objective',
        'assessment' => 'Guard status assessment',
        'plan' => 'Guard status plan',
        'diagnosis_code' => 'R52',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$record->id.'/status', [
            'status' => 'finalized',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

/**
 * @return array{0:string,1:string}
 */
function seedMedicalRecordPlatformScopeAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedMedicalRecordPlatformScopeFacility(
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
function seedMedicalRecordPlatformScopeFacility(
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
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Nairobi',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}
