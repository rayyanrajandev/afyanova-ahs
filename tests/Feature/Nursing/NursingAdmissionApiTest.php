<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Feature tests for POST /api/v1/nursing/admissions (NursingAdmissionController).
 *
 * Verifies that a nurse can escalate a walk-in OPD or triage patient by
 * creating an admission, linking it to the active encounter, and upgrading
 * the encounter type to `inpatient`.
 */
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;

beforeEach(function (): void {
    $this->withoutMiddleware([
        PreventRequestForgery::class,
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
        EnforceTenantIsolationWhenEnabled::class,
    ]);

    FacilityResourceModel::query()->create([
        'resource_type' => 'ward_bed',
        'code' => 'WB-WARD-A-BED-12',
        'name' => 'Ward A Bed 12',
        'ward_name' => 'Ward A',
        'bed_number' => 'Bed 12',
        'location' => 'Admission registry',
        'status' => 'active',
    ]);
});

function nurseAdmissionActor(bool $authorized = true): User
{
    $user = User::factory()->create();
    if ($authorized) {
        $user->givePermissionTo('admissions.create');
    }

    return $user;
}

function createNursingTestPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-ADM-'.strtoupper(Str::random(6)),
        'first_name' => 'Amina',
        'last_name' => 'Juma',
        'gender' => 'female',
        'date_of_birth' => '1995-04-12',
        'phone' => '+255711222333',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function createNursingTestEncounter(PatientModel $patient): EncounterModel
{
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-ADM-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => 'Triage',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Walk-in Triage',
        'status' => 'in_triage',
    ]);

    return EncounterModel::query()->create([
        'encounter_number' => 'ENC-ADM-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'opened',
        'type' => 'outpatient',
        'visit_category' => 'opd_walk_in',
        'opened_at' => now(),
    ]);
}

it('creates an admission and upgrades the encounter to inpatient via nursing API', function (): void {
    $patient = createNursingTestPatient();
    $encounter = createNursingTestEncounter($patient);
    $nurse = nurseAdmissionActor(authorized: true);

    $payload = [
        'patientId' => $patient->id,
        'encounterId' => $encounter->id,
        'appointmentId' => $encounter->appointment_id,
        'admittedAt' => now()->toIso8601String(),
        'admissionReason' => 'Deteriorating respiratory condition in triage',
        'ward' => 'Ward A',
        'bed' => 'Bed 12',
        'notes' => 'Patient needs close monitoring and oxygen support.',
    ];

    $response = $this->actingAs($nurse)->postJson('/api/v1/nursing/admissions', $payload);

    $response->assertCreated();
    $response->assertJsonPath('data.encounter.id', $encounter->id);
    $response->assertJsonPath('data.encounter.type', 'inpatient');

    $encounter->refresh();
    expect($encounter->type)->toBe('inpatient');
    expect($encounter->admission_id)->not->toBeNull();
});

it('rejects admission request if user lacks admissions.create permission', function (): void {
    $patient = createNursingTestPatient();
    $encounter = createNursingTestEncounter($patient);
    $unauthorizedUser = nurseAdmissionActor(authorized: false);

    $payload = [
        'patientId' => $patient->id,
        'encounterId' => $encounter->id,
        'admittedAt' => now()->toIso8601String(),
    ];

    $response = $this->actingAs($unauthorizedUser)->postJson('/api/v1/nursing/admissions', $payload);

    $response->assertForbidden();
});
