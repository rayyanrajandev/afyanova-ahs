<?php

use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coverage for GetTriageQueueStatusCountsUseCase, backing triage/Queue.vue's
 * sticky-header KPI cards. See the use case's own docblock for why this is
 * not a reuse of ListAppointmentStatusCountsUseCase (appointments/status-counts):
 * different semantics (waiting/inProgress are live-queue splits on the
 * triage-claim columns; completed/cancelled are "today" totals, not "of the
 * current queue", since an appointment leaves waiting_triage the moment
 * either happens).
 */
function statusCountsPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTS'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Status', 'last_name' => 'Counts',
        'gender' => 'female', 'date_of_birth' => '1990-01-01',
        'phone' => '+255700000019', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function statusCountsAppointment(array $overrides = []): AppointmentModel
{
    return AppointmentModel::query()->create(array_merge([
        'appointment_number' => 'APTS'.strtoupper(Str::random(8)),
        'patient_id' => statusCountsPatient()->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'waiting_triage',
        'checked_in_at' => now(),
    ], $overrides));
}

it('counts unclaimed waiting_triage rows as waiting and claimed ones as in progress', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    statusCountsAppointment();
    statusCountsAppointment();
    statusCountsAppointment(['triage_owner_user_id' => $user->id, 'triage_owner_assigned_at' => now()]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/triage-queue/status-counts')
        ->assertOk();

    expect($response->json('data.waiting'))->toBe(2);
    expect($response->json('data.inProgress'))->toBe(1);
});

it('counts triaged-today rows as completed regardless of their current status', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    statusCountsAppointment(['status' => 'waiting_provider', 'triaged_at' => now()->subHour()]);
    statusCountsAppointment(['status' => 'in_consultation', 'triaged_at' => now()->subMinutes(10)]);
    // Triaged yesterday — must not count toward today's total.
    statusCountsAppointment(['status' => 'waiting_provider', 'triaged_at' => now()->subDay()]);
    // Never reached triage at all.
    statusCountsAppointment();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/triage-queue/status-counts')
        ->assertOk();

    expect($response->json('data.completed'))->toBe(2);
});

it('counts cancellations only when the visit had already checked in, and only for today', function (): void {
    $user = makeUserWithRole(['appointments.read']);

    // Checked in, then cancelled today — the scenario this card exists for.
    $cancelledAfterCheckIn = statusCountsAppointment(['status' => 'cancelled']);

    // Cancelled directly from scheduled, never checked in — must not count.
    statusCountsAppointment(['status' => 'cancelled', 'checked_in_at' => null]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/triage-queue/status-counts')
        ->assertOk();

    expect($response->json('data.cancelled'))->toBe(1);

    // Cancelled after check-in, but yesterday — must not count toward today.
    AppointmentModel::query()->where('id', $cancelledAfterCheckIn->id)->update(['updated_at' => now()->subDay()]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reception/triage-queue/status-counts')
        ->assertOk();

    expect($response->json('data.cancelled'))->toBe(0);
});

it('forbids triage queue status counts without appointments.read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/reception/triage-queue/status-counts')
        ->assertForbidden();
});
