<?php

namespace App\Modules\Revenue\Application\Support;

use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\PaymentStatus;
use App\Modules\Revenue\Domain\ValueObjects\RefundStatus;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionModel;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionMovementModel;
use App\Modules\Revenue\Infrastructure\Models\PaymentModel;
use App\Modules\Revenue\Infrastructure\Models\RefundModel;

/**
 * What a drawer should contain, derived from the ledger.
 *
 * Never typed by anyone: the Z-report and the close both read this, so the
 * figure a cashier is measured against and the figure the day's report shows
 * cannot disagree. That divergence — two ledgers, two different totals for the
 * same day — is precisely the defect the retired design had.
 */
class CashierSessionTotals
{
    /**
     * @return array{
     *     openingFloat: Money,
     *     cashTaken: Money,
     *     reversals: Money,
     *     refundsPaid: Money,
     *     cashIn: Money,
     *     cashOut: Money,
     *     expectedCash: Money,
     *     paymentCount: int,
     * }
     */
    public function forSession(CashierSessionModel $session): array
    {
        $currency = (string) $session->currency_code;
        $openingFloat = $session->openingFloat();

        // Change given back is already excluded: `amount_minor` is what was
        // applied to charges, not what crossed the counter in both directions.
        $cashTaken = Money::of(
            (int) PaymentModel::query()
                ->where('cashier_session_id', $session->id)
                ->where('status', PaymentStatus::RECORDED->value)
                ->sum('amount_minor'),
            $currency,
        );

        // Reported, not deducted. A reversal flips its original payment to
        // REVERSED, which already removes it from cashTaken above — subtracting
        // the reversal row as well would take the same money out twice. It is
        // surfaced because the number of corrections in a shift is worth
        // seeing on a Z-report, not because it moves the total.
        $reversals = Money::of(
            abs((int) PaymentModel::query()
                ->where('cashier_session_id', $session->id)
                ->where('status', PaymentStatus::REVERSAL->value)
                ->sum('amount_minor')),
            $currency,
        );

        $refundsPaid = Money::of(
            (int) RefundModel::query()
                ->where('paid_from_session_id', $session->id)
                ->where('status', RefundStatus::PAID->value)
                ->sum('amount_minor'),
            $currency,
        );

        $cashIn = Money::of(
            (int) CashierSessionMovementModel::query()
                ->where('cashier_session_id', $session->id)
                ->where('direction', 'in')
                ->sum('amount_minor'),
            $currency,
        );

        $cashOut = Money::of(
            (int) CashierSessionMovementModel::query()
                ->where('cashier_session_id', $session->id)
                ->where('direction', 'out')
                ->sum('amount_minor'),
            $currency,
        );

        $expected = $openingFloat
            ->plus($cashTaken)
            ->plus($cashIn)
            ->minus($refundsPaid)
            ->minus($cashOut);

        return [
            'openingFloat' => $openingFloat,
            'cashTaken' => $cashTaken,
            'reversals' => $reversals,
            'refundsPaid' => $refundsPaid,
            'cashIn' => $cashIn,
            'cashOut' => $cashOut,
            // Deliberately not floored at zero: if refunds and banking exceed
            // takings the drawer really is short, and hiding that behind a
            // zero would hide the only thing the close is looking for.
            'expectedCash' => $expected,
            'paymentCount' => PaymentModel::query()
                ->where('cashier_session_id', $session->id)
                ->where('status', PaymentStatus::RECORDED->value)
                ->count(),
        ];
    }
}
