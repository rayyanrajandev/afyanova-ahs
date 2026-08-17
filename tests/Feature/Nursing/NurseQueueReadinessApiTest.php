<?php

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Billing\Infrastructure\Models\PatientInsuranceModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Feature test for readiness context in GET /api/v1/nursing/tasks and active-visit.
 *
 * Verifies that insurance verification status, financial coverage type, and arrival
 * verification notes flow into the nursing tasks response and active-visit payload.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([
        PreventRequestForgery::class,
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
        EnforceTenantIsolationWhenEnabled::class,
    ]);
});

function readinessNurseUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('patients.read');
    $user->givePermissionTo('service.requests.read');

    return $user;
}

it('returns readiness context in nursing tasks list response', function (): void {
    $nurse = readinessNurseUser();

    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-RD-'.strtoupper(Str::random(6)),
        'first_name' => 'Amani',
        'last_name' => 'Juma',
        'gender' => 'male',
        'date_of_birth' => '1995-03-20',
        'phone' => '+255712000111',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-RD-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => 'Triage',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Routine Visit',
        'status' => 'waiting_triage',
        'financial_coverage_type' => 'insurance',
    ]);

    ArrivalEventModel::query()->create([
        'appointment_id' => $appointment->id,
        'arrival_mode' => 'scheduled_checkin',
        'arrived_at' => now(),
        'verification_notes' => 'Patient has unverified NHIF card',
    ]);

    PatientInsuranceModel::query()->create([
        'patient_id' => $patient->id,
        'insurance_type' => 'national',
        'insurance_provider' => 'NHIF',
        'policy_number' => 'NHIF-12345',
        'status' => 'active',
        'verification_status' => 'unverified',
    ]);

    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC-RD-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'opened',
        'type' => 'outpatient',
        'opened_at' => now(),
    ]);

    $response = $this->actingAs($nurse)->getJson('/api/v1/nursing/tasks');

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $encounter->id);
    $response->assertJsonPath('data.0.readiness.coverageType', 'insurance');
    $response->assertJsonPath('data.0.readiness.insuranceVerified', false);
    $response->assertJsonPath('data.0.readiness.insuranceProvider', 'NHIF');
    $response->assertJsonPath('data.0.readiness.verificationNotes', 'Patient has unverified NHIF card');
});

it('returns readiness context in active-visit payload when selected directly', function (): void {
    $nurse = readinessNurseUser();

    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-RD2-'.strtoupper(Str::random(6)),
        'first_name' => 'Farida',
        'last_name' => 'Hassan',
        'gender' => 'female',
        'date_of_birth' => '1992-08-10',
        'phone' => '+255712333444',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-RD2-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => 'Triage',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Self Pay Visit',
        'status' => 'waiting_triage',
        'financial_coverage_type' => 'self_pay',
    ]);

    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC-RD2-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'opened',
        'type' => 'outpatient',
        'opened_at' => now(),
    ]);

    $response = $this->actingAs($nurse)->getJson("/api/v1/nursing/active-visit/{$patient->id}");

    $response->assertOk();
    $response->assertJsonPath('data.encounterId', $encounter->id);
    $response->assertJsonPath('data.readiness.coverageType', 'self_pay');
    $response->assertJsonPath('data.readiness.insuranceVerified', null);
});
