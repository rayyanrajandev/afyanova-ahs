<?php

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression coverage for Phase 2 of
 * reports/patient-arrival-checkin-modernization-plan.md, closing the gap
 * named in reports/patient-arrival-checkin-audit.md §3: PATCH
 * appointments/{id}/status previously accepted any AppointmentStatus value
 * from any other with no transition guard at all. AppointmentStatus::
 * canTransitionTo() now enforces a real state machine derived from every
 * transition the codebase's actual call sites (and this suite, run in full
 * against the guard before it shipped) rely on.
 *
 * Valid transitions are already exhaustively exercised by AppointmentApiTest;
 * this file focuses on what's actually new — rejecting transitions the old
 * code silently allowed.
 */
it('rejects skipping straight from scheduled to in_consultation', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'in_consultation',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'APPOINTMENT_STATUS_TRANSITION_INVALID')
        ->assertJsonValidationErrors(['status']);

    expect(AppointmentModel::query()->find($created['id']))->status->toBe('scheduled');
});

it('rejects moving a completed appointment to any other status', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'completed',
            'reason' => 'visit done',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'APPOINTMENT_STATUS_TRANSITION_INVALID');

    expect(AppointmentModel::query()->find($created['id']))->status->toBe('completed');
});

it('rejects no_show once the visit has already been checked in', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'no_show',
            'reason' => 'Patient did not arrive',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'APPOINTMENT_STATUS_TRANSITION_INVALID');

    expect(AppointmentModel::query()->find($created['id']))->status->toBe('waiting_triage');
});

it('rejects the generic status endpoint skipping the triage handoff into waiting_provider', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_provider',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'APPOINTMENT_STATUS_TRANSITION_INVALID');

    expect(AppointmentModel::query()->find($created['id']))->status->toBe('waiting_triage');
});

it('still allows cancelling from any non-terminal status', function (): void {
    $user = makeAppointmentUser();
    $patient = makePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'Patient left before triage',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});
