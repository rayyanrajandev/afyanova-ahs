<?php

use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coverage for GetTriageCompletedTodayUseCase, backing triage/Queue.vue's
 * "Completed today" tab — the list counterpart to
 * GetTriageQueueStatusCountsUseCase's `completed` count (deliberately the
 * same query: whereNotNull('triaged_at')->where('triaged_at', '>=', today),
 * no status filter), so this tab existing at all closes the "count shown,
 * nothing to click into" gap a user reported on the live queue.
 */
function completedTodayPatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PTC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Completed', 'last_name' => 'Today',
        'gender' => 'female', 'date_of_birth' => '1990-01-01',
        'phone' => '+255700000029', 'country_code' => 'TZ',
        'status' => 'active',
    ], $overrides));
}

function completedTodayAppointment(array $overrides = []): AppointmentModel
{
    return AppointmentModel::query()->create(array_merge([
        'appointment_number' => 'APTC'.strtoupper(Str::random(8)),
        'patient_id' => completedTodayPatient()->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'waiting_triage',
        'checked_in_at' => now(),
    ], $overrides));
}

it('lists appointments triaged today regardless of their current status', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    $waitingProvider = completedTodayAppointment(['status' => 'waiting_provider', 'triaged_at' => now()->subHour()]);
    $inConsultation = completedTodayAppointment(['status' => 'in_consultation', 'triaged_at' => now()->subMinutes(10)]);
    // Triaged yesterday — must not appear.
    completedTodayAppointment(['status' => 'waiting_provider', 'triaged_at' => now()->subDay()]);
    // Never reached triage at all.
    completedTodayAppointment();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/triage-queue/completed-today')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(2);
    $ids = collect($response->json('data'))->pluck('appointmentId')->all();
    expect($ids)->toEqualCanonicalizing([(string) $inConsultation->id, (string) $waitingProvider->id]);
});

it('orders completed-today entries most-recently-triaged first', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    $earlier = completedTodayAppointment(['status' => 'waiting_provider', 'triaged_at' => now()->subHours(2)]);
    $later = completedTodayAppointment(['status' => 'in_consultation', 'triaged_at' => now()->subMinutes(5)]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/triage-queue/completed-today')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('appointmentId')->all();
    expect($ids)->toBe([(string) $later->id, (string) $earlier->id]);
});

it('includes patient details and the current status on each completed-today entry', function (): void {
    $user = makeUserWithRole(['appointments.read']);
    $patient = completedTodayPatient(['first_name' => 'Halima', 'last_name' => 'Juma']);
    completedTodayAppointment([
        'patient_id' => $patient->id,
        'status' => 'in_consultation',
        'triaged_at' => now()->subMinutes(20),
        'triage_owner_user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/triage-queue/completed-today')
        ->assertOk();

    $entry = $response->json('data.0');
    expect($entry['patientName'])->toBe('Halima Juma');
    expect($entry['patientNumber'])->toBe($patient->patient_number);
    expect($entry['status'])->toBe('in_consultation');
    expect($entry['triageOwnerUserId'])->toBe($user->id);
    expect($entry['triagedAt'])->not->toBeNull();
});

it('paginates completed-today entries', function (): void {
    $user = makeUserWithRole(['appointments.read']);
    for ($i = 0; $i < 3; $i++) {
        completedTodayAppointment(['status' => 'waiting_provider', 'triaged_at' => now()->subMinutes($i + 1)]);
    }

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/triage-queue/completed-today?perPage=2')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(3);
    expect($response->json('meta.lastPage'))->toBe(2);
    expect($response->json('data'))->toHaveCount(2);
});

it('forbids completed-today access without appointments.read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/reception/triage-queue/completed-today')
        ->assertForbidden();
});
