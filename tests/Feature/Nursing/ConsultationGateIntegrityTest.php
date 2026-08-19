<?php

use App\Models\User;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
