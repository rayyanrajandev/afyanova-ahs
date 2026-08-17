<?php

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Reception\Domain\Events\PatientReturnedToReception;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Feature test for POST /api/v1/nursing/return-to-reception/{appointmentId}.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([
        PreventRequestForgery::class,
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
        EnforceTenantIsolationWhenEnabled::class,
    ]);
});

function returnToReceptionNurseActor(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('service.requests.create');

    return $user;
}

it('resets appointment to waiting_triage, cancels encounter, and dispatches PatientReturnedToReception event', function (): void {
    Event::fake([PatientReturnedToReception::class]);

    $nurse = returnToReceptionNurseActor();
    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-RR-'.strtoupper(Str::random(6)),
        'first_name' => 'Amina',
        'last_name' => 'Juma',
        'gender' => 'female',
        'date_of_birth' => '1995-04-10',
        'phone' => '+255788555666',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-RR-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => 'Triage',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Walk-in OPD',
        'status' => 'waiting_triage',
    ]);

    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC-RR-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'opened',
        'type' => 'outpatient',
        'visit_category' => 'opd_walk_in',
        'opened_at' => now(),
    ]);

    $response = $this->actingAs($nurse)->postJson("/api/v1/nursing/return-to-reception/{$appointment->id}", [
        'reason' => 'Missing billing clearance',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'waiting_triage');

    // Appointment stays waiting_triage so reception can process them
    $this->assertDatabaseHas('appointments', [
        'id' => $appointment->id,
        'status' => 'waiting_triage',
    ]);

    // Nurse encounter is cancelled
    $this->assertDatabaseHas('encounters', [
        'id' => $encounter->id,
        'status' => 'cancelled',
    ]);

    Event::assertDispatched(PatientReturnedToReception::class, function ($event) use ($appointment, $patient): bool {
        return $event->appointmentId === $appointment->id
            && $event->patientId === $patient->id
            && $event->reason === 'Missing billing clearance';
    });
});

it('resolves encounter id when encounter id is passed instead of appointment id', function (): void {
    Event::fake([PatientReturnedToReception::class]);

    $nurse = returnToReceptionNurseActor();
    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-RR-'.strtoupper(Str::random(6)),
        'first_name' => 'Fatma',
        'last_name' => 'Hassan',
        'gender' => 'female',
        'date_of_birth' => '1992-06-15',
        'phone' => '+255788999000',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-RR-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => 'Triage',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Walk-in OPD',
        'status' => 'waiting_triage',
    ]);

    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC-RR-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'opened',
        'type' => 'outpatient',
        'visit_category' => 'opd_walk_in',
        'opened_at' => now(),
    ]);

    // Pass encounter ID instead of appointment ID
    $response = $this->actingAs($nurse)->postJson("/api/v1/nursing/return-to-reception/{$encounter->id}", [
        'reason' => 'Unverified insurance',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'waiting_triage');

    Event::assertDispatched(PatientReturnedToReception::class);
});
