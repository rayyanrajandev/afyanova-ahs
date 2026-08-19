<?php

use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashPaymentUseCase;
use App\Modules\Revenue\Application\UseCases\ReverseCashPaymentUseCase;
use App\Modules\Revenue\Domain\Exceptions\CashierSessionRequiredException;
use App\Modules\Revenue\Domain\Exceptions\InsufficientTenderException;
use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\PaymentStatus;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\PaymentModel;
use App\Modules\Revenue\Infrastructure\Models\ReceiptModel;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

function openDrawer(int $cashierUserId = 501, int $floatMinor = 5000000)
{
    return app(OpenCashierSessionUseCase::class)->execute($cashierUserId, $floatMinor);
}

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

it('settles a charge, gives change, and issues a receipt', function (): void {
    $session = openDrawer();
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId);

    // Patient hands over 20,000 for a 15,000 consultation.
    $payment = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 2000000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 501,
    );

    expect($payment->amount_minor)->toBe(1500000)
        ->and($payment->tendered_amount_minor)->toBe(2000000)
        // Change is change — never an over-allocation against the charge.
        ->and($payment->change_amount_minor)->toBe(500000)
        ->and($payment->allocated_amount_minor)->toBe(1500000)
        ->and($payment->status)->toBe(PaymentStatus::RECORDED)
        ->and((string) $payment->cashier_session_id)->toBe((string) $session->id);

    $charge->refresh();

    expect($charge->status)->toBe(ServiceChargeStatus::AUTHORIZED)
        ->and($charge->authorization_basis)->toBe(AuthorizationBasis::PAYMENT)
        ->and($charge->allocated_amount_minor)->toBe(1500000)
        ->and($charge->outstandingAmount()->isZero())->toBeTrue()
        // The prepaid gate now lets this service through.
        ->and($charge->status->permitsFulfilment())->toBeTrue();

    $receipt = ReceiptModel::query()->where('payment_id', $payment->id)->sole();
    $year = now()->format('Y');

    expect($receipt->receipt_number)->toBe("RCP-{$year}-000001")
        ->and($receipt->total_minor)->toBe(1500000)
        ->and($receipt->fiscal_status)->toBe('not_required')
        ->and($receipt->snapshot['lines'])->toHaveCount(1)
        ->and($receipt->snapshot['change'])->toBe('5000.00');
});

it('settles several charges with one note of cash', function (): void {
    openDrawer();
    $patientId = RevenueTestSupport::patientId();

    $consultation = chargeFor($patientId, '15000.00');
    $labTest = chargeFor($patientId, '8000.00');

    $payment = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $consultation->id, (string) $labTest->id],
        tenderedAmountMinor: 2300000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 501,
    );

    expect($payment->amount_minor)->toBe(2300000)
        ->and($payment->change_amount_minor)->toBe(0)
        ->and($payment->allocations)->toHaveCount(2);

    expect($consultation->refresh()->status)->toBe(ServiceChargeStatus::AUTHORIZED)
        ->and($labTest->refresh()->status)->toBe(ServiceChargeStatus::AUTHORIZED);
});

it('rejects a part payment — prepaid means paid', function (): void {
    openDrawer();
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId);

    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1000000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 501,
    );
})->throws(InsufficientTenderException::class, 'does not cover');

it('will not take cash without an open drawer', function (): void {
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId);

    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 501,
    );
})->throws(CashierSessionRequiredException::class, 'Open your drawer');

it('returns the original receipt when the same payment is submitted twice', function (): void {
    openDrawer();
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId);
    $key = (string) Str::uuid();

    $first = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: $key,
        cashierUserId: 501,
    );

    // The double-tap. This must not take the money again.
    $second = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: $key,
        cashierUserId: 501,
    );

    expect((string) $second->id)->toBe((string) $first->id)
        ->and(PaymentModel::query()->count())->toBe(1)
        ->and(ReceiptModel::query()->count())->toBe(1);
});

it('refuses to settle a charge that is already paid', function (): void {
    openDrawer();
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId);

    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 501,
    );

    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 501,
    );
})->throws(RuntimeException::class, 'cannot be paid again');

it('refuses to mix two patients into one payment', function (): void {
    openDrawer();
    $chargeA = chargeFor(RevenueTestSupport::patientId());
    $chargeB = chargeFor(RevenueTestSupport::patientId());

    app(RecordCashPaymentUseCase::class)->execute(
        patientId: (string) $chargeA->patient_id,
        serviceChargeIds: [(string) $chargeA->id, (string) $chargeB->id],
        tenderedAmountMinor: 3000000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 501,
    );
})->throws(InvalidArgumentException::class, 'same patient');

it('releases authorization when a payment is reversed', function (): void {
    openDrawer();
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId);

    $payment = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 501,
    );

    expect($charge->refresh()->status)->toBe(ServiceChargeStatus::AUTHORIZED);

    $reversal = app(ReverseCashPaymentUseCase::class)->execute(
        paymentId: (string) $payment->id,
        reason: 'Wrong patient selected',
        actorUserId: 501,
    );

    $charge->refresh();
    $payment->refresh();

    // The service must not stay cleared on a payment that no longer stands.
    expect($charge->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT)
        ->and($charge->authorization_basis)->toBeNull()
        ->and($charge->allocated_amount_minor)->toBe(0)
        ->and($charge->status->permitsFulfilment())->toBeFalse();

    // The original is preserved, not edited away.
    expect($payment->status)->toBe(PaymentStatus::REVERSED)
        ->and($payment->reversal_reason)->toBe('Wrong patient selected')
        ->and($reversal->amount_minor)->toBe(-1500000)
        ->and((string) $reversal->reversal_of_payment_id)->toBe((string) $payment->id);

    // And the receipt the patient was handed still exists.
    expect(ReceiptModel::query()->where('payment_id', $payment->id)->exists())->toBeTrue();
});

it('will not reverse a payment twice', function (): void {
    openDrawer();
    $patientId = RevenueTestSupport::patientId();
    $charge = chargeFor($patientId);

    $payment = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 501,
    );

    app(ReverseCashPaymentUseCase::class)->execute((string) $payment->id, 'Mistake', 501);
    app(ReverseCashPaymentUseCase::class)->execute((string) $payment->id, 'Mistake again', 501);
})->throws(RuntimeException::class, 'cannot be reversed');
