<?php

use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coverage for GetClinicianQueueStatusCountsUseCase, backing
 * clinician/Queue.vue's sticky-header KPI cards. See the use case's own
 * docblock for the semantics: waiting/onHold are a live split of the
 * waiting_provider population by whether consultation_started_at has ever
 * been set; inProgress is in_consultation; completed is today's totals
 * scoped to visits that actually went through a consultation.
 */
function clinicianStatusCountsPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PCS'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Clinician', 'last_name' => 'Counts',
        'gender' => 'male', 'date_of_birth' => '1988-02-02',
        'phone' => '+255700000020', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function clinicianStatusCountsAppointment(array $overrides = []): AppointmentModel
{
    return AppointmentModel::query()->create(array_merge([
        'appointment_number' => 'APTC'.strtoupper(Str::random(8)),
        'patient_id' => clinicianStatusCountsPatient()->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'waiting_provider',
        'checked_in_at' => now(),
        'triaged_at' => now(),
    ], $overrides));
}

it('splits waiting_provider into waiting and on hold by whether a provider has already been seen', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    clinicianStatusCountsAppointment();
    clinicianStatusCountsAppointment();
    clinicianStatusCountsAppointment(['consultation_started_at' => now()->subMinutes(20)]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/clinician-queue/status-counts')
        ->assertOk();

    expect($response->json('data.waiting'))->toBe(2);
    expect($response->json('data.onHold'))->toBe(1);
});

it('counts in_consultation rows as in progress', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    clinicianStatusCountsAppointment([
        'status' => 'in_consultation',
        'consultation_started_at' => now(),
        'consultation_owner_user_id' => $user->id,
        'consultation_owner_assigned_at' => now(),
    ]);
    clinicianStatusCountsAppointment();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/clinician-queue/status-counts')
        ->assertOk();

    expect($response->json('data.inProgress'))->toBe(1);
});

it('counts completed-today visits that actually went through a consultation, not administrative closures', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    // Went through consultation, completed today — the scenario this card exists for.
    $completedFromConsultation = clinicianStatusCountsAppointment([
        'status' => 'completed',
        'consultation_started_at' => now()->subHour(),
    ]);

    // Administratively closed from scheduled, never saw a consultation — must not count.
    clinicianStatusCountsAppointment(['status' => 'completed', 'consultation_started_at' => null]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/clinician-queue/status-counts')
        ->assertOk();

    expect($response->json('data.completed'))->toBe(1);

    // Completed from consultation, but yesterday — must not count toward today.
    AppointmentModel::query()->where('id', $completedFromConsultation->id)->update(['updated_at' => now()->subDay()]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/clinician-queue/status-counts')
        ->assertOk();

    expect($response->json('data.completed'))->toBe(0);
});

it('forbids clinician queue status counts without appointments.read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/reception/clinician-queue/status-counts')
        ->assertForbidden();
});
