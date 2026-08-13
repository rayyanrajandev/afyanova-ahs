<?php

use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentReferralModel;
use App\Modules\MedicalRecord\Application\Exceptions\DuplicateEncounterDraftMedicalRecordException;
use App\Modules\MedicalRecord\Application\UseCases\CreateMedicalRecordUseCase;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Regression + new-coverage tests for the C-16 fix
 * (reports/clinical-note-audit/15-critical-system-integrity-review.md): the
 * duplicate-draft guard previously only covered appointment-linked
 * consultation_note. It now covers every encounter (appointment- or
 * admission-based) for the note types that are genuinely singular-per-visit
 * (consultation_note, admission_note, discharge_note), while deliberately
 * leaving progress_note/nursing_note/referral_note/procedure_note unguarded,
 * since those legitimately repeat or are scoped to a sub-context (a specific
 * referral or theatre procedure) rather than to the encounter as a whole.
 */
function duplicateGuardPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTDUP'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Duplicate', 'last_name' => 'Guard', 'gender' => 'male',
        'date_of_birth' => '1985-05-05', 'phone' => '+255700000013', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function duplicateGuardAppointment(string $patientId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APTDUP'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'completed',
    ]);
}

function duplicateGuardAdmission(string $patientId): AdmissionModel
{
    return AdmissionModel::query()->create([
        'admission_number' => 'ADMDUP'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'appointment_id' => null,
        'ward' => 'Ward B',
        'bed' => 'B-01',
        'admitted_at' => now()->subHours(3),
        'admission_reason' => 'Observation',
        'status' => 'admitted',
    ]);
}

function duplicateGuardBasePayload(string $patientId, array $overrides = []): array
{
    return array_merge([
        'patient_id' => $patientId,
        'encounter_at' => now()->subHour()->toDateTimeString(),
        'subjective' => 'Duplicate-guard fixture note.',
    ], $overrides);
}

it('blocks a second draft consultation_note for the same appointment-linked encounter', function (): void {
    $patient = duplicateGuardPatient();
    $appointment = duplicateGuardAppointment($patient->id);

    $payload = duplicateGuardBasePayload($patient->id, [
        'appointment_id' => $appointment->id,
        'record_type' => 'consultation_note',
    ]);

    app(CreateMedicalRecordUseCase::class)->execute($payload);

    expect(fn () => app(CreateMedicalRecordUseCase::class)->execute($payload))
        ->toThrow(DuplicateEncounterDraftMedicalRecordException::class);
});

it('blocks a second draft admission_note for the same admission-linked encounter (previously unprotected)', function (): void {
    $patient = duplicateGuardPatient();
    $admission = duplicateGuardAdmission($patient->id);

    $payload = duplicateGuardBasePayload($patient->id, [
        'admission_id' => $admission->id,
        'record_type' => 'admission_note',
    ]);

    app(CreateMedicalRecordUseCase::class)->execute($payload);

    expect(fn () => app(CreateMedicalRecordUseCase::class)->execute($payload))
        ->toThrow(DuplicateEncounterDraftMedicalRecordException::class);
});

it('blocks a second draft discharge_note for the same admission-linked encounter', function (): void {
    $patient = duplicateGuardPatient();
    $admission = duplicateGuardAdmission($patient->id);

    $payload = duplicateGuardBasePayload($patient->id, [
        'admission_id' => $admission->id,
        'record_type' => 'discharge_note',
    ]);

    app(CreateMedicalRecordUseCase::class)->execute($payload);

    expect(fn () => app(CreateMedicalRecordUseCase::class)->execute($payload))
        ->toThrow(DuplicateEncounterDraftMedicalRecordException::class);
});

it('still allows two draft referral_note records for the same encounter targeting different referrals', function (): void {
    $patient = duplicateGuardPatient();
    $appointment = duplicateGuardAppointment($patient->id);
    $referralA = AppointmentReferralModel::query()->create([
        'appointment_id' => $appointment->id,
        'referral_number' => 'REFDUPA'.strtoupper(Str::random(6)),
        'referral_type' => 'internal',
        'priority' => 'routine',
        'target_department' => 'Respiratory',
        'referral_reason' => 'Needs specialty review',
        'requested_at' => now()->subMinutes(20),
        'status' => 'requested',
    ]);
    $referralB = AppointmentReferralModel::query()->create([
        'appointment_id' => $appointment->id,
        'referral_number' => 'REFDUPB'.strtoupper(Str::random(6)),
        'referral_type' => 'internal',
        'priority' => 'routine',
        'target_department' => 'Orthopaedics',
        'referral_reason' => 'Needs specialty review',
        'requested_at' => now()->subMinutes(20),
        'status' => 'requested',
    ]);

    $first = app(CreateMedicalRecordUseCase::class)->execute(duplicateGuardBasePayload($patient->id, [
        'appointment_id' => $appointment->id,
        'appointment_referral_id' => $referralA->id,
        'record_type' => 'referral_note',
    ]));

    $second = app(CreateMedicalRecordUseCase::class)->execute(duplicateGuardBasePayload($patient->id, [
        'appointment_id' => $appointment->id,
        'appointment_referral_id' => $referralB->id,
        'record_type' => 'referral_note',
    ]));

    expect($first['id'])->not->toBe($second['id']);
});

it('still allows two draft procedure_note records for the same encounter targeting different theatre procedures', function (): void {
    $patient = duplicateGuardPatient();
    $appointment = duplicateGuardAppointment($patient->id);
    $procedureA = TheatreProcedureModel::query()->create([
        'procedure_number' => 'THDUPA'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'procedure_type' => 'laparoscopy',
        'operating_clinician_user_id' => \App\Models\User::factory()->create()->id,
        'scheduled_at' => now()->subMinutes(10),
        'status' => 'planned',
    ]);
    $procedureB = TheatreProcedureModel::query()->create([
        'procedure_number' => 'THDUPB'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'procedure_type' => 'thoracotomy',
        'operating_clinician_user_id' => \App\Models\User::factory()->create()->id,
        'scheduled_at' => now()->subMinutes(10),
        'status' => 'planned',
    ]);

    $first = app(CreateMedicalRecordUseCase::class)->execute(duplicateGuardBasePayload($patient->id, [
        'appointment_id' => $appointment->id,
        'theatre_procedure_id' => $procedureA->id,
        'record_type' => 'procedure_note',
    ]));

    $second = app(CreateMedicalRecordUseCase::class)->execute(duplicateGuardBasePayload($patient->id, [
        'appointment_id' => $appointment->id,
        'theatre_procedure_id' => $procedureB->id,
        'record_type' => 'procedure_note',
    ]));

    expect($first['id'])->not->toBe($second['id']);
});

it('still allows multiple draft progress_note records for the same encounter', function (): void {
    $patient = duplicateGuardPatient();
    $admission = duplicateGuardAdmission($patient->id);

    $payload = duplicateGuardBasePayload($patient->id, [
        'admission_id' => $admission->id,
        'record_type' => 'progress_note',
    ]);

    $first = app(CreateMedicalRecordUseCase::class)->execute($payload);
    $second = app(CreateMedicalRecordUseCase::class)->execute($payload);
    $third = app(CreateMedicalRecordUseCase::class)->execute($payload);

    expect([$first['id'], $second['id'], $third['id']])->toEqual(array_unique([$first['id'], $second['id'], $third['id']]));
});

// ---------------------------------------------------------------------------
// HTTP-level: the duplicate-draft error must key to whichever visit-context
// field the request actually supplied, not always 'appointmentId'. Fixes the
// gap surfaced when C-16 was broadened to admission-linked visits: the
// controller previously hardcoded errors.appointmentId for this exception,
// which made no sense for an admission-only request with no appointmentId at
// all.
// ---------------------------------------------------------------------------

it('keys the duplicate-draft error to appointmentId for an appointment-linked visit', function (): void {
    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = duplicateGuardPatient();
    $appointment = duplicateGuardAppointment($patient->id);

    $payload = medicalRecordPayload($patient->id, [
        'appointmentId' => $appointment->id,
        'recordType' => 'consultation_note',
    ]);

    $this->actingAs($user)->postJson('/api/v1/medical-records', $payload)->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', $payload)
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['appointmentId']])
        ->assertJsonMissingPath('errors.admissionId');
});

it('keys the duplicate-draft error to admissionId for an admission-linked visit with no appointment', function (): void {
    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makeMedicalRecordUser();
    $patient = duplicateGuardPatient();
    $admission = duplicateGuardAdmission($patient->id);

    $payload = medicalRecordPayload($patient->id, [
        'admissionId' => $admission->id,
        'recordType' => 'admission_note',
    ]);

    $this->actingAs($user)->postJson('/api/v1/medical-records', $payload)->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/medical-records', $payload)
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['admissionId']])
        ->assertJsonMissingPath('errors.appointmentId');
});
