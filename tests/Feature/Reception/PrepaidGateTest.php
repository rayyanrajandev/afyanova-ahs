<?php

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashPaymentUseCase;
use App\Modules\Revenue\Application\UseCases\ReverseCashPaymentUseCase;
use App\Modules\Revenue\Application\UseCases\WaiveServiceChargeUseCase;
use App\Modules\Revenue\Domain\Services\ServiceAuthorizationReaderInterface;
use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

/**
 * The prepaid rule, end to end: a patient cannot reach a clinician before the
 * consultation has been paid for.
 *
 * These exercise the domain layer directly rather than through HTTP — the
 * cashier's routes arrive in Phase 6 — but the gate itself lives in
 * CheckInUseCase, which is what reception actually calls today.
 */
function consultationChargeFor(string $appointmentId): ?ServiceChargeModel
{
    return ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::CONSULTATION->value)
        ->where('source_workflow_id', $appointmentId)
        ->first();
}

it('treats a service with no charge as authorized', function (): void {
    // The gate must not block services whose charge kind has not been switched
    // on. Turning a gate on means raising charges for it, not tightening this.
    $reader = app(ServiceAuthorizationReaderInterface::class);
    $authorization = $reader->describe(ChargeSourceKind::LABORATORY_ORDER, (string) Str::uuid());

    expect($authorization->authorized)->toBeTrue()
        ->and($authorization->status)->toBe('not_charged')
        ->and($authorization->chargeId)->toBeNull();
});

it('reports an unpaid consultation as unauthorized, with the amount still due', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-GATE-1', '15000.00');
    $appointmentId = (string) Str::uuid();

    app(RaiseServiceChargeUseCase::class)->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $appointmentId,
        chargeableItemId: $item['chargeableItemId'],
        description: 'General outpatient consultation',
        appointmentId: $appointmentId,
    );

    $authorization = app(ServiceAuthorizationReaderInterface::class)
        ->describe(ChargeSourceKind::CONSULTATION, $appointmentId);

    expect($authorization->authorized)->toBeFalse()
        ->and($authorization->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT->value)
        ->and($authorization->amountDue->toDecimalString())->toBe('15000.00')
        ->and($authorization->amountPaid->isZero())->toBeTrue()
        ->and($authorization->requirement)->toBe('TZS 15000.00 outstanding.');
});

it('opens the gate once the charge is paid', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-GATE-2', '15000.00');
    $appointmentId = (string) Str::uuid();
    $patientId = RevenueTestSupport::patientId();

    $charge = app(RaiseServiceChargeUseCase::class)->execute(
        patientId: $patientId,
        sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $appointmentId,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation',
        appointmentId: $appointmentId,
    );

    $reader = app(ServiceAuthorizationReaderInterface::class);
    expect($reader->isAuthorized(ChargeSourceKind::CONSULTATION, $appointmentId))->toBeFalse();

    app(OpenCashierSessionUseCase::class)->execute(901, 5000000);
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 901,
    );

    expect($reader->isAuthorized(ChargeSourceKind::CONSULTATION, $appointmentId))->toBeTrue();
});

it('opens the gate on a waiver, with no money taken', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-GATE-3', '15000.00');
    $appointmentId = (string) Str::uuid();

    $charge = app(RaiseServiceChargeUseCase::class)->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $appointmentId,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation',
        appointmentId: $appointmentId,
    );

    app(WaiveServiceChargeUseCase::class)->execute(
        serviceChargeId: (string) $charge->id,
        basis: AuthorizationBasis::EMERGENCY,
        reason: 'Collapsed in the waiting area',
        approvedByUserId: 777,
    );

    $authorization = app(ServiceAuthorizationReaderInterface::class)
        ->describe(ChargeSourceKind::CONSULTATION, $appointmentId);

    expect($authorization->authorized)->toBeTrue()
        ->and($authorization->basis)->toBe(AuthorizationBasis::EMERGENCY)
        // Still owed on paper — cleared by authority, not by payment.
        ->and($authorization->amountDue->toDecimalString())->toBe('15000.00');
});

it('shuts the gate again when the payment is reversed', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-GATE-4', '15000.00');
    $appointmentId = (string) Str::uuid();
    $patientId = RevenueTestSupport::patientId();

    $charge = app(RaiseServiceChargeUseCase::class)->execute(
        patientId: $patientId,
        sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $appointmentId,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation',
        appointmentId: $appointmentId,
    );

    app(OpenCashierSessionUseCase::class)->execute(902, 5000000);
    $payment = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 902,
    );

    $reader = app(ServiceAuthorizationReaderInterface::class);
    expect($reader->isAuthorized(ChargeSourceKind::CONSULTATION, $appointmentId))->toBeTrue();

    app(ReverseCashPaymentUseCase::class)
        ->execute((string) $payment->id, 'Wrong patient', 902);

    expect($reader->isAuthorized(ChargeSourceKind::CONSULTATION, $appointmentId))->toBeFalse();
});

it('answers for a whole queue in one query', function (): void {
    $paidAppointment = (string) Str::uuid();
    $unpaidAppointment = (string) Str::uuid();
    $unknownAppointment = (string) Str::uuid();
    $patientId = RevenueTestSupport::patientId();

    $item = RevenueTestSupport::pricedItem('CONSULT-GATE-5', '15000.00');
    $raise = app(RaiseServiceChargeUseCase::class);

    $paidCharge = $raise->execute(
        patientId: $patientId, sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $paidAppointment, chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation', appointmentId: $paidAppointment,
    );
    $raise->execute(
        patientId: RevenueTestSupport::patientId(), sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $unpaidAppointment, chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation', appointmentId: $unpaidAppointment,
    );

    app(OpenCashierSessionUseCase::class)->execute(903, 5000000);
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $paidCharge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 903,
    );

    $map = app(ServiceAuthorizationReaderInterface::class)->describeMany(
        ChargeSourceKind::CONSULTATION,
        [$paidAppointment, $unpaidAppointment, $unknownAppointment],
    );

    expect($map[$paidAppointment]->authorized)->toBeTrue()
        ->and($map[$unpaidAppointment]->authorized)->toBeFalse()
        ->and($map[$unknownAppointment]->status)->toBe('not_charged');
});

it('puts awaiting_payment ahead of a nursing claim on the flow board', function (): void {
    // Nothing clinical may start before the charge clears, so this state
    // outranks a nursing pickup the same way the terminal states do.
    expect(PatientFlowStep::fromAppointmentStatus('awaiting_payment'))
        ->toBe(PatientFlowStep::AWAITING_PAYMENT)
        ->and(PatientFlowStep::fromAppointmentStatus('awaiting_payment', hasNursingContact: true))
        ->toBe(PatientFlowStep::AWAITING_PAYMENT);
});

it('lets a visit reach triage from awaiting_payment but never no-show', function (): void {
    $awaiting = AppointmentStatus::AWAITING_PAYMENT;

    expect($awaiting->canTransitionTo(AppointmentStatus::WAITING_TRIAGE->value))->toBeTrue()
        ->and($awaiting->canTransitionTo(AppointmentStatus::CANCELLED->value))->toBeTrue()
        // The patient demonstrably arrived; no-show is meaningless now.
        ->and($awaiting->canTransitionTo(AppointmentStatus::NO_SHOW->value))->toBeFalse();

    expect(AppointmentStatus::SCHEDULED->canTransitionTo(AppointmentStatus::AWAITING_PAYMENT->value))->toBeTrue();
});
