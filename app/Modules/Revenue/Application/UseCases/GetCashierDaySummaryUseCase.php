<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Revenue\Application\Support\CashierSessionTotals;
use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\PaymentStatus;
use App\Modules\Revenue\Domain\ValueObjects\RefundStatus;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionModel;
use App\Modules\Revenue\Infrastructure\Models\PaymentModel;
use App\Modules\Revenue\Infrastructure\Models\ReceiptModel;
use App\Modules\Revenue\Infrastructure\Models\RefundModel;
use Illuminate\Support\Carbon;

/**
 * A facility's takings for one day, across every drawer.
 *
 * Derived from the ledger, never typed. The retired design could not make that
 * claim — POS and Billing each closed their own day and reported different
 * numbers for the same one — and there is exactly one ledger now, so the day
 * total and each Z-report are the same arithmetic over the same rows.
 */
class GetCashierDaySummaryUseCase
{
    public function __construct(
        private readonly CashierSessionTotals $sessionTotals,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
        private readonly DefaultCurrencyResolverInterface $currencyResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $date = null): array
    {
        $day = $date !== null ? Carbon::parse($date) : now();
        $facilityId = $this->scopeContext->facilityId();
        $currency = $this->currencyResolver->resolve();

        $scoped = fn ($query) => $facilityId === null
            ? $query
            : $query->where('facility_id', $facilityId);

        $takings = Money::of((int) $scoped(
            PaymentModel::query()
                ->where('status', PaymentStatus::RECORDED->value)
                ->whereDate('received_at', $day->toDateString()),
        )->sum('amount_minor'), $currency);

        $reversed = Money::of(abs((int) $scoped(
            PaymentModel::query()
                ->where('status', PaymentStatus::REVERSAL->value)
                ->whereDate('received_at', $day->toDateString()),
        )->sum('amount_minor')), $currency);

        $refunded = Money::of((int) $scoped(
            RefundModel::query()
                ->where('status', RefundStatus::PAID->value)
                ->whereDate('paid_at', $day->toDateString()),
        )->sum('amount_minor'), $currency);

        $sessions = $scoped(
            CashierSessionModel::query()->whereDate('opened_at', $day->toDateString()),
        )->get();

        $sessionRows = $sessions->map(function (CashierSessionModel $session): array {
            $totals = $this->sessionTotals->forSession($session);

            return [
                'sessionId' => (string) $session->id,
                'sessionNumber' => (string) $session->session_number,
                'cashierUserId' => (int) $session->cashier_user_id,
                'status' => $session->status->value,
                'openedAt' => $session->opened_at?->toIso8601String(),
                'closedAt' => $session->closed_at?->toIso8601String(),
                'openingFloat' => $totals['openingFloat']->toDecimalString(),
                'cashTaken' => $totals['cashTaken']->toDecimalString(),
                'expectedCash' => $totals['expectedCash']->toDecimalString(),
                'declaredCash' => $session->declared_cash_minor === null
                    ? null
                    : Money::of((int) $session->declared_cash_minor, (string) $session->currency_code)->toDecimalString(),
                'variance' => $session->variance_minor === null
                    ? null
                    : Money::of((int) $session->variance_minor, (string) $session->currency_code)->toDecimalString(),
                'paymentCount' => $totals['paymentCount'],
            ];
        })->values()->all();

        return [
            'date' => $day->toDateString(),
            'currencyCode' => $currency,
            'grossTakings' => $takings->toDecimalString(),
            'reversed' => $reversed->toDecimalString(),
            'refunded' => $refunded->toDecimalString(),
            'netTakings' => $takings->minus($refunded)->toDecimalString(),
            'receiptsIssued' => $scoped(
                ReceiptModel::query()->whereDate('issued_at', $day->toDateString()),
            )->count(),
            // Every reprint is recorded; a day with many is worth a look.
            'reprints' => (int) $scoped(
                ReceiptModel::query()->whereDate('issued_at', $day->toDateString()),
            )->sum('reprint_count'),
            'sessions' => $sessionRows,
            'sessionsAwaitingApproval' => $sessions
                ->filter(fn (CashierSessionModel $s): bool => $s->status === CashierSessionStatus::PENDING_APPROVAL)
                ->count(),
        ];
    }
}
