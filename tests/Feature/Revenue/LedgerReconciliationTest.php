<?php

use App\Modules\Revenue\Application\Support\CashierSessionTotals;
use App\Modules\Revenue\Application\UseCases\ApproveRefundUseCase;
use App\Modules\Revenue\Application\UseCases\CloseCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashMovementUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashPaymentUseCase;
use App\Modules\Revenue\Application\UseCases\RequestRefundUseCase;
use App\Modules\Revenue\Application\UseCases\ReverseCashPaymentUseCase;
use App\Modules\Revenue\Domain\ValueObjects\CashMovementReason;
use App\Modules\Revenue\Infrastructure\Models\PaymentAllocationModel;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

/**
 * Whatever sequence of counter events occurs, the books must still add up.
 *
 * The retired design could not make this claim: two ledgers with one day close
 * meant a facility taking cash at a register and closing the day in billing
 * reported two different numbers for the same day. There is one ledger now, so
 * this is checkable — and worth checking against a messy day rather than a
 * tidy one.
 */
it('reconciles after a messy shift of payments, reversals, refunds and banking', function (): void {
    $cashier = 701;
    $supervisor = 999;
    $session = app(OpenCashierSessionUseCase::class)->execute($cashier, 5000000);

    $paid = [];

    // Twelve ordinary payments of varying size, some with change.
    foreach (range(1, 12) as $i) {
        $patientId = RevenueTestSupport::patientId();
        $charge = chargeFor($patientId, number_format(1000 * $i, 2, '.', ''));

        $due = (int) $charge->patient_responsibility_minor;
        $tendered = $i % 3 === 0 ? $due + 50000 : $due;

        $paid[] = app(RecordCashPaymentUseCase::class)->execute(
            patientId: $patientId,
            serviceChargeIds: [(string) $charge->id],
            tenderedAmountMinor: $tendered,
            idempotencyKey: (string) Str::uuid(),
            cashierUserId: $cashier,
        );
    }

    // Two mistakes corrected in-session.
    app(ReverseCashPaymentUseCase::class)->execute((string) $paid[2]->id, 'Wrong patient', $cashier);
    app(ReverseCashPaymentUseCase::class)->execute((string) $paid[7]->id, 'Duplicate entry', $cashier);

    // One refund, properly approved by someone else.
    $refund = app(RequestRefundUseCase::class)->execute(
        (string) $paid[4]->id,
        (int) $paid[4]->amount_minor,
        'Patient left before being seen',
        $cashier,
    );
    app(ApproveRefundUseCase::class)->execute((string) $refund->id, $supervisor, (string) $session->id);

    // Banking and a top-up.
    app(RecordCashMovementUseCase::class)->execute(
        (string) $session->id, CashMovementReason::BANKING_DROP, 3000000, $cashier, 'To safe',
    );
    app(RecordCashMovementUseCase::class)->execute(
        (string) $session->id, CashMovementReason::FLOAT_TOP_UP, 500000, $cashier, 'More change',
    );

    $totals = app(CashierSessionTotals::class)->forSession($session->refresh());

    // Independent recomputation straight from the tables — deliberately not
    // reusing the service under test.
    $recordedCash = (int) DB::table('payments')
        ->where('cashier_session_id', $session->id)
        ->where('status', 'recorded')
        ->sum('amount_minor');
    $refundsPaid = (int) DB::table('refunds')
        ->where('paid_from_session_id', $session->id)
        ->where('status', 'paid')
        ->sum('amount_minor');
    $movedIn = (int) DB::table('cashier_session_movements')
        ->where('cashier_session_id', $session->id)->where('direction', 'in')->sum('amount_minor');
    $movedOut = (int) DB::table('cashier_session_movements')
        ->where('cashier_session_id', $session->id)->where('direction', 'out')->sum('amount_minor');

    $expected = 5000000 + $recordedCash + $movedIn - $refundsPaid - $movedOut;

    expect($totals['expectedCash']->minorUnits)->toBe($expected);

    // Counting exactly that must close the drawer without escalation.
    $result = app(CloseCashierSessionUseCase::class)->execute((string) $session->id, $expected, $cashier);

    expect($result['requiresApproval'])->toBeFalse()
        ->and($result['session']->variance_minor)->toBe(0);
});

it('keeps every charge allocation equal to the sum of its live allocation rows', function (): void {
    $cashier = 702;
    app(OpenCashierSessionUseCase::class)->execute($cashier, 5000000);

    $payments = [];

    foreach (range(1, 8) as $i) {
        $patientId = RevenueTestSupport::patientId();
        $charge = chargeFor($patientId, number_format(2000 * $i, 2, '.', ''));

        $payments[] = app(RecordCashPaymentUseCase::class)->execute(
            patientId: $patientId,
            serviceChargeIds: [(string) $charge->id],
            tenderedAmountMinor: (int) $charge->patient_responsibility_minor,
            idempotencyKey: (string) Str::uuid(),
            cashierUserId: $cashier,
        );
    }

    app(ReverseCashPaymentUseCase::class)->execute((string) $payments[3]->id, 'Correction', $cashier);
    app(ReverseCashPaymentUseCase::class)->execute((string) $payments[6]->id, 'Correction', $cashier);

    // The invariant: a charge's cached allocated figure is always exactly the
    // sum of the allocation rows that still exist for it. Drift here is how a
    // patient ends up either paying twice or walking through the gate unpaid.
    foreach (ServiceChargeModel::query()->get() as $charge) {
        $summed = (int) PaymentAllocationModel::query()
            ->where('service_charge_id', $charge->id)
            ->sum('amount_minor');

        expect((int) $charge->allocated_amount_minor)->toBe(
            $summed,
            "charge {$charge->charge_number} drifted from its allocations",
        );
    }
});

it('never lets allocations exceed the payment that created them', function (): void {
    $cashier = 703;
    app(OpenCashierSessionUseCase::class)->execute($cashier, 5000000);

    $patientId = RevenueTestSupport::patientId();
    $a = chargeFor($patientId, '15000.00');
    $b = chargeFor($patientId, '8000.00');

    $payment = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $a->id, (string) $b->id],
        tenderedAmountMinor: 2300000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: $cashier,
    );

    $allocated = (int) PaymentAllocationModel::query()
        ->where('payment_id', $payment->id)
        ->sum('amount_minor');

    expect($allocated)->toBe((int) $payment->amount_minor)
        ->and($allocated)->toBeLessThanOrEqual((int) $payment->tendered_amount_minor);
});
