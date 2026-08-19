<?php

use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\PayerClass;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;

it('lets an unpaid charge become authorized or be cancelled', function (): void {
    $pending = ServiceChargeStatus::PENDING_PAYMENT;

    expect($pending->canTransitionTo(ServiceChargeStatus::AUTHORIZED))->toBeTrue()
        ->and($pending->canTransitionTo(ServiceChargeStatus::CANCELLED))->toBeTrue()
        ->and($pending->canTransitionTo(ServiceChargeStatus::FULFILLED))->toBeFalse();
});

it('lets an authorized charge fall back to owing when a payment is reversed', function (): void {
    // The ordinary same-session correction at a counter. If this were not
    // allowed, fixing a mistyped amount would require a refund.
    expect(ServiceChargeStatus::AUTHORIZED->canTransitionTo(ServiceChargeStatus::PENDING_PAYMENT))->toBeTrue();
});

it('never lets a cancelled or refunded charge come back', function (): void {
    foreach (ServiceChargeStatus::cases() as $target) {
        expect(ServiceChargeStatus::CANCELLED->canTransitionTo($target))->toBeFalse()
            ->and(ServiceChargeStatus::REFUNDED->canTransitionTo($target))->toBeFalse();
    }
});

it('never lets an unpaid charge be fulfilled — this is the prepaid rule', function (): void {
    expect(ServiceChargeStatus::DRAFT->permitsFulfilment())->toBeFalse()
        ->and(ServiceChargeStatus::PENDING_PAYMENT->permitsFulfilment())->toBeFalse()
        ->and(ServiceChargeStatus::CANCELLED->permitsFulfilment())->toBeFalse()
        ->and(ServiceChargeStatus::AUTHORIZED->permitsFulfilment())->toBeTrue()
        ->and(ServiceChargeStatus::FULFILLED->permitsFulfilment())->toBeTrue();
});

it('counts draft and pending as outstanding, and nothing else', function (): void {
    $outstanding = array_values(array_filter(
        ServiceChargeStatus::cases(),
        static fn (ServiceChargeStatus $s): bool => $s->isOutstanding(),
    ));

    expect($outstanding)->toBe([ServiceChargeStatus::DRAFT, ServiceChargeStatus::PENDING_PAYMENT]);
});

it('treats only self-pay as implemented', function (): void {
    expect(PayerClass::SELF_PAY->isImplemented())->toBeTrue();

    foreach ([PayerClass::INSURANCE, PayerClass::EMPLOYER, PayerClass::GOVERNMENT,
        PayerClass::DONOR, PayerClass::OTHER] as $class) {
        expect($class->isImplemented())->toBeFalse()
            ->and($class->requiresPayerContract())->toBeTrue();
    }
});

it('maps the appointment financial coverage vocabulary onto payer classes', function (): void {
    expect(PayerClass::fromFinancialCoverage('self_pay'))->toBe(PayerClass::SELF_PAY)
        ->and(PayerClass::fromFinancialCoverage('insurance'))->toBe(PayerClass::INSURANCE)
        ->and(PayerClass::fromFinancialCoverage(null))->toBe(PayerClass::SELF_PAY)
        ->and(PayerClass::fromFinancialCoverage('nonsense'))->toBe(PayerClass::SELF_PAY);
});

it('keeps payer authorization reserved and unreachable', function (): void {
    expect(AuthorizationBasis::PAYER_AUTHORIZATION->isImplemented())->toBeFalse()
        ->and(AuthorizationBasis::PAYMENT->isImplemented())->toBeTrue()
        ->and(AuthorizationBasis::WAIVER->requiresReason())->toBeTrue()
        ->and(AuthorizationBasis::EMERGENCY->requiresReason())->toBeTrue()
        ->and(AuthorizationBasis::PAYMENT->requiresReason())->toBeFalse();
});

it('implements only consultation and manual charge sources for now', function (): void {
    expect(ChargeSourceKind::CONSULTATION->isImplemented())->toBeTrue()
        ->and(ChargeSourceKind::MANUAL->isImplemented())->toBeTrue()
        ->and(ChargeSourceKind::LABORATORY_ORDER->isImplemented())->toBeFalse();

    // A manual charge has no clinical record behind it, so it is the one kind
    // that may be raised repeatedly for the same patient.
    expect(ChargeSourceKind::MANUAL->requiresSourceReference())->toBeFalse()
        ->and(ChargeSourceKind::CONSULTATION->requiresSourceReference())->toBeTrue();
});
