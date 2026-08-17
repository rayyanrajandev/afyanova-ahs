<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * `GET nursing/tasks` (NurseQueueController::index) now carries a `visit`
 * context block per row (appointmentStatus, stage, arrivalMode,
 * visitCategory, encounterType, isAdmitted) so the Nursing UI can show a
 * walk-in OPD patient's journey position (e.g. "waiting_triage") instead of
 * a bare "opened encounter". This file locks in that payload contract.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([
        PreventRequestForgery::class,
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);
});

function nurseVisitActor(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('service.requests.read');

    return $user;
}

function nurseVisitPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-NV-'.strtoupper(Str::random(6)),
        'first_name' => 'Rehema',
        'last_name' => 'Mwangi',
        'gender' => 'female',
        'date_of_birth' => '1992-11-08',
        'phone' => '+255700555666',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function nurseVisitEncounter(
    PatientModel $patient,
    string $appointmentStatus,
    string $arrivalMode,
    string $visitCategory,
    string $encounterType,
    ?User $triageOwner = null,
    bool $admitted = false,
): EncounterModel {
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-NV-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'General Medicine',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Walk-in OPD',
        'status' => $appointmentStatus,
        'triage_owner_user_id' => $triageOwner?->id,
    ]);

    ArrivalEventModel::query()->create([
        'appointment_id' => $appointment->id,
        'arrival_mode' => $arrivalMode,
        'arrived_at' => now(),
    ]);

    $admissionId = null;
    if ($admitted) {
        $admissionId = AdmissionModel::query()->create([
            'admission_number' => 'ADM-NV-'.strtoupper(Str::random(6)),
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
            'admitted_at' => now(),
            'status' => 'admitted',
        ])->id;
    }

    return EncounterModel::query()->create([
        'encounter_number' => 'ENC-NV-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'admission_id' => $admissionId,
        'status' => 'opened',
        'type' => $encounterType,
        'visit_category' => $visitCategory,
        'opened_at' => now(),
    ]);
}

it('surfaces a walk-in OPD patient waiting for triage with the full visit context', function (): void {
    $patient = nurseVisitPatient();
    $encounter = nurseVisitEncounter(
        $patient,
        appointmentStatus: 'waiting_triage',
        arrivalMode: 'walk_in',
        visitCategory: 'opd_walk_in',
        encounterType: 'outpatient',
    );
    $nurse = nurseVisitActor();

    $response = $this->actingAs($nurse)->getJson('/api/v1/nursing/tasks');

    $response->assertOk();
    $response->assertJsonPath("data.0.id", $encounter->id);
    $response->assertJsonPath("data.0.visit.appointmentStatus", 'waiting_triage');
    $response->assertJsonPath("data.0.visit.stage", 'waiting_triage');
    $response->assertJsonPath("data.0.visit.arrivalMode", 'walk_in');
    $response->assertJsonPath("data.0.visit.visitCategory", 'opd_walk_in');
    $response->assertJsonPath("data.0.visit.encounterType", 'outpatient');
    $response->assertJsonPath("data.0.visit.isAdmitted", false);
});

it('derives in_triage when a triage owner has claimed the appointment', function (): void {
    $patient = nurseVisitPatient();
    $triageOwner = User::factory()->create();
    $encounter = nurseVisitEncounter(
        $patient,
        appointmentStatus: 'waiting_triage',
        arrivalMode: 'walk_in',
        visitCategory: 'opd_walk_in',
        encounterType: 'outpatient',
        triageOwner: $triageOwner,
    );
    $nurse = nurseVisitActor();

    $response = $this->actingAs($nurse)->getJson('/api/v1/nursing/tasks');

    $response->assertOk();
    $response->assertJsonPath("data.0.visit.stage", 'in_triage');
    $response->assertJsonPath("data.0.visit.arrivalMode", 'walk_in');
});

it('marks an inpatient encounter as admitted with no appointment stage', function (): void {
    $patient = nurseVisitPatient();
    $encounter = nurseVisitEncounter(
        $patient,
        appointmentStatus: 'waiting_triage',
        arrivalMode: 'walk_in',
        visitCategory: 'opd_walk_in',
        encounterType: 'inpatient',
        admitted: true,
    );
    $nurse = nurseVisitActor();

    $response = $this->actingAs($nurse)->getJson('/api/v1/nursing/tasks');

    $response->assertOk();
    $response->assertJsonPath("data.0.id", $encounter->id);
    $response->assertJsonPath("data.0.visit.isAdmitted", true);
    $response->assertJsonPath("data.0.visit.encounterType", 'inpatient');
});
