<?php

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Feature test for GET /api/v1/nursing/active-visit/{patientId}.
 *
 * Verifies that selecting a patient resolves their active encounter and visit
 * journey context (e.g. stage, arrival mode, encounter type) even when
 * selected from the general Patients list rather than the Tasks queue.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([
        PreventRequestForgery::class,
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
        EnforceTenantIsolationWhenEnabled::class,
    ]);
});

function activeVisitNurseActor(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('patients.read');

    return $user;
}

it('returns null data when patient has no active open encounter', function (): void {
    $nurse = activeVisitNurseActor();
    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-AV-'.strtoupper(Str::random(6)),
        'first_name' => 'Khadija',
        'last_name' => 'Ali',
        'gender' => 'female',
        'date_of_birth' => '1990-01-01',
        'phone' => '+255788111222',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $response = $this->actingAs($nurse)->getJson("/api/v1/nursing/active-visit/{$patient->id}");

    $response->assertOk();
    $response->assertJsonPath('data', null);
});

it('resolves active visit context for a patient with an open encounter', function (): void {
    $nurse = activeVisitNurseActor();
    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-AV-'.strtoupper(Str::random(6)),
        'first_name' => 'Baraka',
        'last_name' => 'Said',
        'gender' => 'male',
        'date_of_birth' => '1988-06-15',
        'phone' => '+255788333444',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-AV-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => 'Triage',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Walk-in OPD',
        'status' => 'waiting_triage',
    ]);

    ArrivalEventModel::query()->create([
        'appointment_id' => $appointment->id,
        'arrival_mode' => 'walk_in',
        'arrived_at' => now(),
    ]);

    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC-AV-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'opened',
        'type' => 'outpatient',
        'visit_category' => 'opd_walk_in',
        'opened_at' => now(),
    ]);

    $response = $this->actingAs($nurse)->getJson("/api/v1/nursing/active-visit/{$patient->id}");

    $response->assertOk();
    $response->assertJsonPath('data.encounterId', $encounter->id);
    $response->assertJsonPath('data.visit.appointmentStatus', 'waiting_triage');
    $response->assertJsonPath('data.visit.stage', 'waiting_triage');
    $response->assertJsonPath('data.visit.arrivalMode', 'walk_in');
    $response->assertJsonPath('data.visit.visitCategory', 'opd_walk_in');
});
