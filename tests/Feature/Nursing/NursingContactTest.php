<?php

/**
 * A nurse picking a patient up, and putting them down again.
 *
 * PatientFlowStep::WITH_NURSE was added because "nursing had no picked-up state
 * at all, which is why nursing steps were invisible on the board". The state
 * exists; nothing asserted that claiming actually reaches it, that a second
 * nurse is told rather than silently overridden, or that letting go clears it.
 *
 * The last of those matters most in practice: a stale nursing claim makes every
 * queue in the building read "With Nurse" for a patient nobody is with.
 */

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use Illuminate\Support\Str;
use Tests\Feature\Nursing\NursingTestSupport;

it('records the nurse who picks a patient up', function (): void {
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit();

    $this->actingAs($nurse)
        ->postJson("/api/v1/nursing/visits/{$visit['encounterId']}/claim")
        ->assertOk()
        ->assertJsonPath('data.step', PatientFlowStep::WITH_NURSE->value)
        ->assertJsonPath('data.recorded', true);

    $appointment = AppointmentModel::query()->find($visit['appointmentId']);

    expect($appointment->nursing_contact_user_id)->toBe($nurse->id)
        ->and($appointment->nursing_contact_started_at)->not->toBeNull();
});

it('shows the patient as with a nurse on the worklist once claimed', function (): void {
    // The point of the state: a nurse actively with a patient must not still
    // read as "waiting" on the very queue that nurse is working from.
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit();

    $this->actingAs($nurse)->postJson("/api/v1/nursing/visits/{$visit['encounterId']}/claim")->assertOk();

    $row = collect(
        $this->actingAs($nurse)->getJson('/api/v1/nursing/tasks')->assertOk()->json('data')
    )->firstWhere('id', $visit['encounterId']);

    expect($row['visit']['stage'])->toBe(PatientFlowStep::WITH_NURSE->value);
});

it('tells a second nurse who already has the patient instead of taking over', function (): void {
    $first = NursingTestSupport::nurse();
    $second = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit();

    $this->actingAs($first)->postJson("/api/v1/nursing/visits/{$visit['encounterId']}/claim")->assertOk();

    $this->actingAs($second)
        ->postJson("/api/v1/nursing/visits/{$visit['encounterId']}/claim")
        ->assertOk()
        ->assertJsonPath('data.recorded', false)
        ->assertJsonPath('data.heldByUserId', $first->id);

    // The original holder is untouched — a conflict is reported, not resolved.
    expect(AppointmentModel::query()->find($visit['appointmentId'])->nursing_contact_user_id)
        ->toBe($first->id);
});

it('treats the holding nurse re-claiming as a no-op, not a conflict', function (): void {
    // `recorded: false` here means "already WITH_NURSE by this same nurse", not
    // a refusal — the use case says so in as many words. What distinguishes it
    // from a genuine conflict is that the holder reported back is the caller,
    // and the contact is left exactly as it was.
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit();

    $this->actingAs($nurse)->postJson("/api/v1/nursing/visits/{$visit['encounterId']}/claim")->assertOk();
    $startedAt = AppointmentModel::query()->find($visit['appointmentId'])->nursing_contact_started_at;

    $this->actingAs($nurse)
        ->postJson("/api/v1/nursing/visits/{$visit['encounterId']}/claim")
        ->assertOk()
        ->assertJsonPath('data.step', PatientFlowStep::WITH_NURSE->value)
        ->assertJsonPath('data.heldByUserId', $nurse->id)
        ->assertJsonPath('data.recorded', false);

    $appointment = AppointmentModel::query()->find($visit['appointmentId']);

    expect($appointment->nursing_contact_user_id)->toBe($nurse->id)
        ->and($appointment->nursing_contact_started_at->toISOString())->toBe($startedAt->toISOString());
});

it('clears the contact when the nurse lets the patient go', function (): void {
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit();

    $this->actingAs($nurse)->postJson("/api/v1/nursing/visits/{$visit['encounterId']}/claim")->assertOk();
    $this->actingAs($nurse)
        ->postJson("/api/v1/nursing/visits/{$visit['encounterId']}/release", ['reason' => 'Observations done'])
        ->assertOk();

    $appointment = AppointmentModel::query()->find($visit['appointmentId']);

    expect($appointment->nursing_contact_user_id)->toBeNull()
        ->and($appointment->nursing_contact_started_at)->toBeNull();
});

it('clears the contact when the patient is handed back to reception', function (): void {
    // Stated as an invariant in ReturnPatientToReceptionUseCase: without this
    // the visit keeps reading "With Nurse" in every queue after the nurse let
    // them go. Nothing asserted it until now.
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit(['withArrival' => true]);

    $this->actingAs($nurse)->postJson("/api/v1/nursing/visits/{$visit['encounterId']}/claim")->assertOk();

    $this->actingAs($nurse)
        ->postJson("/api/v1/nursing/return-to-reception/{$visit['appointmentId']}", ['reason' => 'Card missing'])
        ->assertOk();

    expect(AppointmentModel::query()->find($visit['appointmentId'])->nursing_contact_user_id)
        ->toBeNull();
});

it('refuses to claim a visit that no longer exists', function (): void {
    $this->actingAs(NursingTestSupport::nurse())
        ->postJson('/api/v1/nursing/visits/'.Str::uuid().'/claim')
        ->assertStatus(422);
});
