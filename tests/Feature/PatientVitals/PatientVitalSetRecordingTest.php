<?php

use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\PatientFlow\Domain\Events\PatientFlowBoardUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coverage for recording a vital-sign set with the Tanzania-vitals fields
 * (height, BMI, pain score) added 2026-08-14.
 *
 * Verifies the new fields persist through the controller and that BMI is
 * auto-computed from height + weight when not supplied explicitly.
 */

function vitalSetActor(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('patient.vitals.record');
    $user->givePermissionTo('patients.read');

    return $user;
}

function vitalSetPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTV'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Neema', 'last_name' => 'Massawe',
        'gender' => 'female', 'date_of_birth' => '1990-06-20',
        'phone' => '+255712000444', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

it('persists height, pain score and auto-computes BMI from height and weight', function () {
    $user = vitalSetActor();
    $patient = vitalSetPatient();

    $response = $this->actingAs($user)->postJson('/api/v1/nursing/vitals', [
        'patientId' => $patient->id,
        'heightCm' => 170,
        'weightKg' => 70,
        'painScore' => 4,
    ]);

    $response->assertStatus(201);

    $saved = App\Modules\PatientVitals\Infrastructure\Models\PatientVitalSetModel::first();
    expect($saved)->not->toBeNull()
        ->and((float) $saved->height_cm)->toBe(170.0)
        ->and((float) $saved->weight_kg)->toBe(70.0)
        ->and((int) $saved->pain_score)->toBe(4)
        ->and((float) $saved->bmi)->toBe(24.22); // 70 / (1.70^2)
});

it('respects an explicitly supplied BMI instead of recomputing', function () {
    $user = vitalSetActor();
    $patient = vitalSetPatient();

    $response = $this->actingAs($user)->postJson('/api/v1/nursing/vitals', [
        'patientId' => $patient->id,
        'heightCm' => 170,
        'weightKg' => 70,
        'bmi' => 30.0,
        'painScore' => 2,
    ]);

    $response->assertStatus(201);

    $saved = App\Modules\PatientVitals\Infrastructure\Models\PatientVitalSetModel::first();
    expect($saved)->not->toBeNull()
        ->and((float) $saved->bmi)->toBe(30.0);
});

it('returns height, bmi and pain score from the latest endpoint', function () {
    $user = vitalSetActor();
    $patient = vitalSetPatient();

    $this->actingAs($user)->postJson('/api/v1/nursing/vitals', [
        'patientId' => $patient->id,
        'heightCm' => 165,
        'weightKg' => 60,
        'painScore' => 1,
    ])->assertStatus(201);

    $response = $this->actingAs($user)->getJson("/api/v1/nursing/vitals/{$patient->id}");

    $response->assertOk()
        ->assertJsonPath('data.latest.heightCm', 165)
        ->assertJsonPath('data.latest.weightKg', 60)
        ->assertJsonPath('data.latest.painScore', 1);
});

it('advances appointment status to waiting_provider and updates triage_vitals_summary upon recording vitals', function () {
    Event::fake([PatientFlowBoardUpdated::class]);

    $user = vitalSetActor();
    $patient = vitalSetPatient();

    $appointment = AppointmentModel::query()->create([
        'id' => (string) Str::uuid(),
        'appointment_number' => 'APT-VT-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => 'Triage',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'OPD Visit',
        'status' => 'waiting_triage',
    ]);

    $response = $this->actingAs($user)->postJson('/api/v1/nursing/vitals', [
        'patientId' => $patient->id,
        'appointmentId' => $appointment->id,
        'temperatureC' => 36.6,
        'systolicBpMmhg' => 120,
        'diastolicBpMmhg' => 80,
        'heartRateBpm' => 72,
        'oxygenSaturationPct' => 98,
        'weightKg' => 65,
    ]);

    $response->assertStatus(201);

    $appointment->refresh();
    // dump($appointment->toArray());
    expect($appointment->status)->toBe('waiting_provider')
        ->and($appointment->triage_vitals_summary)->toContain('T: 36.6°C')
        ->and($appointment->triage_vitals_summary)->toContain('BP: 120/80 mmHg')
        ->and($appointment->triaged_at)->not->toBeNull();

    Event::assertDispatched(PatientFlowBoardUpdated::class);
});
