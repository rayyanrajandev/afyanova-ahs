<?php

use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coverage for POST /reception/queue/reorder (Volume 2.1 §10.3 "Reorder",
 * Volume 3.7 T5.5). Setup goes straight through the models rather than the
 * `/reception/walk-ins` API — that endpoint's own test fixture
 * (`ReceptionQueueApiTest::queueUser()`) is missing `appointment.check-in`
 * relative to what the route actually requires, a pre-existing drift
 * documented in Volume 3.7 §16 #14/#15, not something this file depends on
 * or attempts to fix.
 */
function reorderQueuePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTR'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Reorder', 'last_name' => 'Fixture', 'gender' => 'female',
        'date_of_birth' => '1990-01-01', 'phone' => '+255700000019', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function reorderQueueUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('appointment.check-in');
    // GET /reception/queue (still routes/api.php, not yet moved to
    // api-workspaces.php — a pre-existing gap noted, not fixed, here) is
    // gated on a different permission than the reorder write itself.
    $user->givePermissionTo('appointments.read');

    return $user;
}

/** Appointment already in `waiting_triage` with a recorded arrival mode — the queue's actual precondition. */
function waitingTriageAppointment(string $arrivalMode, ?string $checkedInMinutesAgo = null): AppointmentModel
{
    $patient = reorderQueuePatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTR'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'department' => 'Outpatient',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'waiting_triage',
        'checked_in_at' => $checkedInMinutesAgo !== null
            ? now()->subMinutes((int) $checkedInMinutesAgo)
            : now(),
    ]);

    ArrivalEventModel::query()->create([
        'appointment_id' => $appointment->id,
        'arrival_mode' => $arrivalMode,
        'arrived_at' => now(),
    ]);

    return $appointment;
}

it('persists a reorder within the same tier', function () {
    $user = reorderQueueUser();
    $first = waitingTriageAppointment('walk_in', checkedInMinutesAgo: '10');
    $second = waitingTriageAppointment('walk_in', checkedInMinutesAgo: '5');

    // Both walk-in — reversing their natural (oldest-wait-first) order is
    // a same-tier reorder, always allowed.
    $response = $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/reorder', [
            'appointmentIds' => [$second->id, $first->id],
        ]);

    $response->assertOk()->assertJsonPath('data.reordered', 2);

    expect(AppointmentModel::find($second->id)->queue_position)->toBe(1);
    expect(AppointmentModel::find($first->id)->queue_position)->toBe(2);
});

it('rejects an order that moves a walk-in ahead of an emergency arrival', function () {
    $user = reorderQueueUser();
    $emergency = waitingTriageAppointment('emergency');
    $walkIn = waitingTriageAppointment('walk_in');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/reorder', [
            // Walk-in first — crosses the emergency tier's hard floor.
            'appointmentIds' => [$walkIn->id, $emergency->id],
        ]);

    $response->assertStatus(422)->assertJsonPath('code', 'QUEUE_REORDER_CROSSES_TIER');

    expect(AppointmentModel::find($walkIn->id)->queue_position)->toBeNull();
    expect(AppointmentModel::find($emergency->id)->queue_position)->toBeNull();
});

it('allows reordering within a tier even when a higher tier exists elsewhere in the queue', function () {
    $user = reorderQueueUser();
    $emergency = waitingTriageAppointment('emergency');
    $firstWalkIn = waitingTriageAppointment('walk_in', checkedInMinutesAgo: '20');
    $secondWalkIn = waitingTriageAppointment('walk_in', checkedInMinutesAgo: '10');

    // Full submitted order: emergency still first, the two walk-ins swapped.
    $response = $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/reorder', [
            'appointmentIds' => [$emergency->id, $secondWalkIn->id, $firstWalkIn->id],
        ]);

    $response->assertOk();
    expect(AppointmentModel::find($emergency->id)->queue_position)->toBe(1);
    expect(AppointmentModel::find($secondWalkIn->id)->queue_position)->toBe(2);
    expect(AppointmentModel::find($firstWalkIn->id)->queue_position)->toBe(3);
});

it('writes an audit log entry for each appointment whose position changed', function () {
    $user = reorderQueueUser();
    $first = waitingTriageAppointment('walk_in', checkedInMinutesAgo: '10');
    $second = waitingTriageAppointment('walk_in', checkedInMinutesAgo: '5');

    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/reorder', [
            'appointmentIds' => [$second->id, $first->id],
        ])
        ->assertOk();

    $this->assertDatabaseHas('appointment_audit_logs', [
        'appointment_id' => $second->id,
        'action' => 'queue.reordered',
        'actor_id' => $user->id,
    ]);
    $this->assertDatabaseHas('appointment_audit_logs', [
        'appointment_id' => $first->id,
        'action' => 'queue.reordered',
        'actor_id' => $user->id,
    ]);
});

it('requires appointment.check-in permission', function () {
    $user = User::factory()->create();
    $appointment = waitingTriageAppointment('walk_in');

    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/reorder', [
            'appointmentIds' => [$appointment->id],
        ])
        ->assertForbidden();
});

it('reflects the persisted order when the queue is refetched', function () {
    $user = reorderQueueUser();
    $first = waitingTriageAppointment('walk_in', checkedInMinutesAgo: '10');
    $second = waitingTriageAppointment('walk_in', checkedInMinutesAgo: '5');

    // Without a reorder, oldest-wait-first puts $first ahead of $second.
    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/reorder', [
            'appointmentIds' => [$second->id, $first->id],
        ])
        ->assertOk();

    $queue = $this->actingAs($user)
        ->getJson('/api/v1/reception/queue?stage=waiting_triage')
        ->assertOk()
        ->json('data');

    expect($queue[0]['appointmentId'])->toBe($second->id);
    expect($queue[0]['queuePosition'])->toBe(1);
    expect($queue[1]['appointmentId'])->toBe($first->id);
    expect($queue[1]['queuePosition'])->toBe(2);
});

it('clears queue_position when the appointment leaves the stage it was reordered in', function () {
    $user = reorderQueueUser();
    $first = waitingTriageAppointment('walk_in', checkedInMinutesAgo: '10');
    $second = waitingTriageAppointment('walk_in', checkedInMinutesAgo: '5');

    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/reorder', [
            'appointmentIds' => [$second->id, $first->id],
        ])
        ->assertOk();
    expect(AppointmentModel::find($second->id)->queue_position)->toBe(1);

    // Cancel — a real status transition — should reset the manual position.
    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/'.$second->id.'/cancel', [
            'reason' => 'Patient left',
        ])
        ->assertOk();

    expect(AppointmentModel::find($second->id)->queue_position)->toBeNull();
});
