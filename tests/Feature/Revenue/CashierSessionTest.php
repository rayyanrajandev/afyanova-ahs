<?php

use App\Modules\Revenue\Application\UseCases\ApproveCashierSessionVarianceUseCase;
use App\Modules\Revenue\Application\UseCases\ApproveRefundUseCase;
use App\Modules\Revenue\Application\UseCases\CloseCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashMovementUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashPaymentUseCase;
use App\Modules\Revenue\Application\UseCases\RequestRefundUseCase;
use App\Modules\Revenue\Application\UseCases\ReverseCashPaymentUseCase;
use App\Modules\Revenue\Application\UseCases\WaiveServiceChargeUseCase;
use App\Modules\Revenue\Domain\Exceptions\CashierSessionAlreadyOpenException;
use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use App\Modules\Revenue\Domain\ValueObjects\CashMovementReason;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\RefundStatus;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

if (! function_exists('chargeFor')) {
    function chargeFor(string $patientId, string $price = '15000.00', ?string $code = null)
    {
        $item = RevenueTestSupport::pricedItem($code ?? 'CONSULT-'.Str::upper(Str::random(6)), $price);

        return app(RaiseServiceChargeUseCase::class)->execute(
            patientId: $patientId,
            sourceKind: ChargeSourceKind::MANUAL,
            sourceId: null,
            chargeableItemId: $item['chargeableItemId'],
            description: 'Consultation',
        );
    }
}

it('allows one open drawer per cashier', function (): void {
    app(OpenCashierSessionUseCase::class)->execute(601, 5000000);
    app(OpenCashierSessionUseCase::class)->execute(601, 5000000);
})->throws(CashierSessionAlreadyOpenException::class, 'already have drawer');

it('lets two cashiers hold their own drawers at once', function (): void {
    $a = app(OpenCashierSessionUseCase::class)->execute(602, 5000000);
    $b = app(OpenCashierSessionUseCase::class)->execute(603, 3000000);

    expect($a->id)->not->toBe($b->id)
        ->and($a->status)->toBe(CashierSessionStatus::OPEN)
        ->and($b->status)->toBe(CashierSessionStatus::OPEN);
});

it('closes cleanly when the count matches the ledger', function (): void {
    $session = app(OpenCashierSessionUseCase::class)->execute(604, 5000000);
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId, '15000.00');

    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 604,
    );

    // Float 50,000 + 15,000 taken = 65,000 expected.
    $result = app(CloseCashierSessionUseCase::class)->execute((string) $session->id, 6500000, 604);

    expect($result['requiresApproval'])->toBeFalse()
        ->and($result['session']->status)->toBe(CashierSessionStatus::CLOSED)
        ->and($result['session']->expected_cash_minor)->toBe(6500000)
        ->and($result['session']->declared_cash_minor)->toBe(6500000)
        ->and($result['session']->variance_minor)->toBe(0);
});

it('holds a short drawer open until a second person signs it off', function (): void {
    $session = app(OpenCashierSessionUseCase::class)->execute(605, 5000000);
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId, '15000.00');

    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 605,
    );

    // 2,000 short.
    $result = app(CloseCashierSessionUseCase::class)->execute((string) $session->id, 6300000, 605);

    expect($result['requiresApproval'])->toBeTrue()
        ->and($result['session']->status)->toBe(CashierSessionStatus::PENDING_APPROVAL)
        ->and($result['session']->variance_minor)->toBe(-200000)
        // Not closed: the drawer stays accountable until someone looks.
        ->and($result['session']->closed_at)->toBeNull();
});

it('will not let a cashier approve the variance on their own drawer', function (): void {
    $session = app(OpenCashierSessionUseCase::class)->execute(606, 5000000);
    app(CloseCashierSessionUseCase::class)->execute((string) $session->id, 4000000, 606);

    app(ApproveCashierSessionVarianceUseCase::class)->execute((string) $session->id, 606, 'Counted twice');
})->throws(RuntimeException::class, 'cannot approve the variance on their own drawer');

it('closes a short drawer once a supervisor approves', function (): void {
    $session = app(OpenCashierSessionUseCase::class)->execute(607, 5000000);
    app(CloseCashierSessionUseCase::class)->execute((string) $session->id, 4900000, 607);

    $approved = app(ApproveCashierSessionVarianceUseCase::class)
        ->execute((string) $session->id, 999, 'Note lost between till and safe; logged with security.');

    expect($approved->status)->toBe(CashierSessionStatus::CLOSED)
        ->and($approved->approved_by_user_id)->toBe(999)
        ->and($approved->variance_minor)->toBe(-100000)
        ->and($approved->approval_note)->toContain('security');
});

it('requires a note when approving a variance', function (): void {
    $session = app(OpenCashierSessionUseCase::class)->execute(608, 5000000);
    app(CloseCashierSessionUseCase::class)->execute((string) $session->id, 4000000, 608);

    app(ApproveCashierSessionVarianceUseCase::class)->execute((string) $session->id, 999, '   ');
})->throws(RuntimeException::class, 'requires a note');

it('counts float top-ups and banking drops in the expected cash', function (): void {
    $session = app(OpenCashierSessionUseCase::class)->execute(609, 5000000);

    app(RecordCashMovementUseCase::class)->execute(
        (string) $session->id, CashMovementReason::FLOAT_TOP_UP, 1000000, 609, 'Extra change ordered',
    );
    app(RecordCashMovementUseCase::class)->execute(
        (string) $session->id, CashMovementReason::BANKING_DROP, 2000000, 609, 'Mid-shift banking',
    );

    // 50,000 + 10,000 − 20,000 = 40,000.
    $result = app(CloseCashierSessionUseCase::class)->execute((string) $session->id, 4000000, 609);

    expect($result['session']->expected_cash_minor)->toBe(4000000)
        ->and($result['requiresApproval'])->toBeFalse();
});

it('takes a reversal back out of the drawer total', function (): void {
    $session = app(OpenCashierSessionUseCase::class)->execute(610, 5000000);
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId, '15000.00');

    $payment = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 610,
    );

    app(ReverseCashPaymentUseCase::class)
        ->execute((string) $payment->id, 'Wrong patient', 610);

    // Back to just the float.
    $result = app(CloseCashierSessionUseCase::class)->execute((string) $session->id, 5000000, 610);

    expect($result['session']->expected_cash_minor)->toBe(5000000)
        ->and($result['requiresApproval'])->toBeFalse();
});

it('will not let the same person request and approve a refund', function (): void {
    $session = app(OpenCashierSessionUseCase::class)->execute(611, 5000000);
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId, '15000.00');

    $payment = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 611,
    );

    $refund = app(RequestRefundUseCase::class)->execute(
        (string) $payment->id, 1500000, 'Service not delivered', 611,
    );

    app(ApproveRefundUseCase::class)->execute((string) $refund->id, 611, (string) $session->id);
})->throws(RuntimeException::class, 'cannot be approved by the person who requested it');

it('pays an approved refund out of a named drawer and reduces its expected cash', function (): void {
    $session = app(OpenCashierSessionUseCase::class)->execute(612, 5000000);
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId, '15000.00');

    $payment = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 612,
    );

    $refund = app(RequestRefundUseCase::class)->execute(
        (string) $payment->id, 1500000, 'Patient left before being seen', 612,
    );

    $paid = app(ApproveRefundUseCase::class)
        ->execute((string) $refund->id, 999, (string) $session->id, 'Approved at counter');

    expect($paid->status)->toBe(RefundStatus::PAID)
        ->and((string) $paid->paid_from_session_id)->toBe((string) $session->id);

    // 50,000 float + 15,000 taken − 15,000 refunded.
    $result = app(CloseCashierSessionUseCase::class)->execute((string) $session->id, 5000000, 612);

    expect($result['session']->expected_cash_minor)->toBe(5000000)
        ->and($result['requiresApproval'])->toBeFalse();
});

it('refuses to refund more than was paid', function (): void {
    app(OpenCashierSessionUseCase::class)->execute(613, 5000000);
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId, '15000.00');

    $payment = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 613,
    );

    app(RequestRefundUseCase::class)->execute((string) $payment->id, 2000000, 'Too much', 613);
})->throws(RuntimeException::class, 'would exceed what was paid');

it('authorizes a charge by waiver without any money changing hands', function (): void {
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId, '15000.00');

    $waived = app(WaiveServiceChargeUseCase::class)->execute(
        serviceChargeId: (string) $charge->id,
        basis: AuthorizationBasis::WAIVER,
        reason: 'Indigent patient, approved by hospital administrator',
        approvedByUserId: 999,
        requestedByUserId: 501,
    );

    expect($waived->status)->toBe(ServiceChargeStatus::AUTHORIZED)
        ->and($waived->authorization_basis)->toBe(AuthorizationBasis::WAIVER)
        ->and($waived->status->permitsFulfilment())->toBeTrue()
        // No money moved: the charge is cleared but nothing was allocated.
        ->and($waived->allocated_amount_minor)->toBe(0);
});

it('lets an emergency override clear a charge for treatment now', function (): void {
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId, '15000.00');

    $cleared = app(WaiveServiceChargeUseCase::class)->execute(
        serviceChargeId: (string) $charge->id,
        basis: AuthorizationBasis::EMERGENCY,
        reason: 'Unconscious on arrival — treat first',
        approvedByUserId: 777,
    );

    expect($cleared->authorization_basis)->toBe(AuthorizationBasis::EMERGENCY)
        ->and($cleared->status->permitsFulfilment())->toBeTrue();
});

it('will not accept a waiver without a reason', function (): void {
    $charge = chargeFor(RevenueTestSupport::patientId(), '15000.00');

    app(WaiveServiceChargeUseCase::class)->execute(
        serviceChargeId: (string) $charge->id,
        basis: AuthorizationBasis::WAIVER,
        reason: '  ',
        approvedByUserId: 999,
    );
})->throws(InvalidArgumentException::class, 'must say why');

it('refuses to grant the reserved payer-authorization basis', function (): void {
    $charge = chargeFor(RevenueTestSupport::patientId(), '15000.00');

    // Reserved for a future insurer adapter. Nothing in this phase may set it.
    app(WaiveServiceChargeUseCase::class)->execute(
        serviceChargeId: (string) $charge->id,
        basis: AuthorizationBasis::PAYER_AUTHORIZATION,
        reason: 'Covered by scheme',
        approvedByUserId: 999,
    );
})->throws(InvalidArgumentException::class, 'not a basis a person can grant');
