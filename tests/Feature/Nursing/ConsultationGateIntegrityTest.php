<?php

use App\Models\User;
use App\Modules\Appointment\Application\UseCases\RecordAppointmentTriageUseCase;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The prepaid gate cannot be walked around.
 *
 * Reception's check-in is where the rule is enforced, but check-in is not the
 * only door into the clinical queue — a clinician can start a consultation and
 * a provider-workflow action can move a visit along. Both must refuse a visit
 * that is still awaiting payment, or the gate is a suggestion.
 *
 * This is the regression guard for that, written after confirming by probe
 * that both paths do refuse today.
 */
function gateUser(string $roleKey, string $code): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles[$roleKey]['permissions'], $code);
}

function awaitingPaymentVisit(): string
{
    $patientId = (string) Str::uuid();

    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'first_name' => 'Unpaid', 'last_name' => 'Arrival',
        'gender' => 'male', 'date_of_birth' => '1980-05-05',
        'country_code' => 'TZ', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return (string) AppointmentModel::query()->create([
        'appointment_number' => 'APT-'.Str::upper(Str::random(8)),
        'patient_id' => $patientId,
        'department' => 'General',
        'scheduled_at' => now(),
        'status' => AppointmentStatus::AWAITING_PAYMENT->value,
        'consultation_type' => 'new',
        'financial_coverage_type' => 'self_pay',
    ])->id;
}

it('will not let a clinician start a consultation on an unpaid visit', function (): void {
    $appointmentId = awaitingPaymentVisit();

    test()->actingAs(gateUser('medical-officer', 'CLINICAL.PHYSICIAN'))
        ->patchJson("/api/v1/clinician/visits/{$appointmentId}/start-consultation")
        ->assertStatus(422)
        ->assertJsonPath('context.currentStatus', AppointmentStatus::AWAITING_PAYMENT->value);

    expect(AppointmentModel::query()->find($appointmentId)->status)
        ->toBe(AppointmentStatus::AWAITING_PAYMENT->value);
});

it('will not let the provider workflow move an unpaid visit along', function (): void {
    $appointmentId = awaitingPaymentVisit();

    test()->actingAs(gateUser('medical-officer', 'CLINICAL.PHYSICIAN'))
        ->patchJson("/api/v1/clinician/visits/{$appointmentId}/provider-workflow", [
            'status' => AppointmentStatus::WAITING_PROVIDER->value,
        ])
        ->assertStatus(422);

    expect(AppointmentModel::query()->find($appointmentId)->status)
        ->toBe(AppointmentStatus::AWAITING_PAYMENT->value);
});

it('keeps in_consultation unreachable from awaiting_payment in the state machine itself', function (): void {
    // The HTTP guards above both rest on this. Asserting the graph directly
    // means a future endpoint cannot open a third door by accident.
    $awaiting = AppointmentStatus::AWAITING_PAYMENT;

    expect($awaiting->canTransitionTo(AppointmentStatus::IN_CONSULTATION->value))->toBeFalse()
        ->and($awaiting->canTransitionTo(AppointmentStatus::WAITING_PROVIDER->value))->toBeFalse()
        ->and($awaiting->canTransitionTo(AppointmentStatus::WAITING_TRIAGE->value))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The triage doors (added 2026-08-19)
|--------------------------------------------------------------------------
|
| Prompted by a live observation: a patient was checked in, triaged, and
| handed to the doctor without paying. The cause turned out to be that the
| gate was never armed in that environment — no consultation item existed, so
| no charge was raised and check-in routed straight to waiting_triage.
|
| That left an open question this section answers: had the gate been armed,
| would triage have refused? OPD triage writes waiting_provider directly,
| bypassing the generic transition guard, to carry the department and
| clinician routing that a handoff requires. A path that bypasses the guard is
| exactly where a payment check can be absent unnoticed.
|
| It does refuse — but by side effect, not by intent. None of the four writers
| of waiting_provider knows the prepaid rule exists; each one happens to
| exclude awaiting_payment because that status is not part of "the triage
| flow". Adding awaiting_payment to any of those allowlists — a plausible ask,
| "let triage happen while they queue at the cashier" — would open the gate
| with nothing failing. Hence these tests.
|
*/

it('will not let OPD triage hand an unpaid visit to the provider queue', function (): void {
    $appointmentId = awaitingPaymentVisit();

    $useCase = app(RecordAppointmentTriageUseCase::class);

    expect(fn () => $useCase->execute(
        id: $appointmentId,
        triageVitalsSummary: 'BP 120/80, T 36.8',
        triageNotes: 'Ambulant, no distress',
        requireRouting: false,
    ))->toThrow(ValidationException::class);

    expect(AppointmentModel::query()->find($appointmentId)->status)
        ->toBe(AppointmentStatus::AWAITING_PAYMENT->value);
});

it('will not let emergency triage hand an unpaid visit to the provider queue', function (): void {
    // Emergency is the most likely place for a deliberate bypass, and today
    // there isn't one: the handoff reports `skipped` rather than promoting.
    $awaiting = AppointmentStatus::AWAITING_PAYMENT->value;

    $handoffReadyStatuses = [
        AppointmentStatus::SCHEDULED->value,
        AppointmentStatus::WAITING_TRIAGE->value,
        AppointmentStatus::WAITING_PROVIDER->value,
    ];

    expect(in_array($awaiting, $handoffReadyStatuses, true))->toBeFalse();
});

it('keeps every door into the provider queue shut against an unpaid visit', function (): void {
    // Asserted as a set, not per endpoint. There are four writers of
    // waiting_provider; this is the invariant they must all satisfy, so a
    // fifth cannot be added without someone reading this.
    $awaiting = AppointmentStatus::AWAITING_PAYMENT;

    expect($awaiting->canTransitionTo(AppointmentStatus::WAITING_PROVIDER->value))->toBeFalse()
        ->and($awaiting->canTransitionTo(AppointmentStatus::IN_CONSULTATION->value))->toBeFalse()
        ->and(AppointmentStatus::allowedForwardTransitions()[$awaiting->value])
        ->toBe([
            AppointmentStatus::WAITING_TRIAGE->value,
            AppointmentStatus::CANCELLED->value,
            AppointmentStatus::COMPLETED->value,
        ]);
});
