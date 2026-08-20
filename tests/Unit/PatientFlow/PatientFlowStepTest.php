<?php

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;

/**
 * Every step must be able to say what it is.
 *
 * `label()` is an exhaustive `match`, and PHP `match` throws
 * UnhandledMatchError on a missing case rather than returning null. So a case
 * added to this enum without a label does not degrade — it takes down whatever
 * asked. AWAITING_PAYMENT was added with the prepaid model, mapped in
 * fromAppointmentStatus(), and never given a label; releasing a nurse's claim
 * on an unpaid visit and rendering that visit's flow timeline both died with a
 * 500 that reached the browser as an unexplained failure.
 *
 * This iterates the enum rather than listing cases, so the next case added is
 * covered the moment it exists.
 */
it('gives every flow step a staff-facing label', function (): void {
    foreach (PatientFlowStep::cases() as $step) {
        expect($step->label())->toBeString()->not->toBe('');
    }
});

it('labels the awaiting-payment step', function (): void {
    expect(PatientFlowStep::AWAITING_PAYMENT->label())->toBe('Awaiting payment');
});

it('answers isActiveContact and isTerminal for every step without throwing', function (): void {
    // Both are `match` too. They carry a `default`, so they cannot throw today —
    // this pins that, because dropping the default is a one-character change.
    foreach (PatientFlowStep::cases() as $step) {
        expect($step->isActiveContact())->toBeBool()
            ->and($step->isTerminal())->toBeBool();
    }
});

it('resolves an awaiting-payment appointment to the awaiting-payment step', function (): void {
    expect(PatientFlowStep::fromAppointmentStatus(AppointmentStatus::AWAITING_PAYMENT->value))
        ->toBe(PatientFlowStep::AWAITING_PAYMENT);
});
