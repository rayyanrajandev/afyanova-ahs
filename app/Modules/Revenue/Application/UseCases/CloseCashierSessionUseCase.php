<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Revenue\Application\Support\CashierSessionTotals;
use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionModel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Close a drawer against a blind count.
 *
 * The cashier submits what they counted; only then does the ledger reveal what
 * it expected. That ordering is the entire control, and it is enforced here
 * rather than in the UI on purpose — a close screen that merely hides the
 * expected figure is defeated by the network tab. No endpoint returns
 * `expectedCash` for an open session, so the number genuinely is not available
 * to the person counting until they have committed to a figure.
 *
 * A variance beyond the configured tolerance leaves the session in
 * PENDING_APPROVAL. It is not closed, and the cashier cannot close it: a
 * second person has to look.
 */
class CloseCashierSessionUseCase
{
    public function __construct(
        private readonly CashierSessionTotals $totals,
        private readonly RevenueAuditRecorderInterface $auditRecorder,
    ) {}

    /**
     * @return array{session: CashierSessionModel, totals: array<string, mixed>, requiresApproval: bool}
     */
    public function execute(
        string $cashierSessionId,
        int $declaredCashMinor,
        int $actorUserId,
    ): array {
        if ($declaredCashMinor < 0) {
            throw new RuntimeException('A counted amount cannot be negative.');
        }

        return DB::transaction(function () use ($cashierSessionId, $declaredCashMinor, $actorUserId): array {
            $session = CashierSessionModel::query()->lockForUpdate()->findOrFail($cashierSessionId);

            if ($session->status === CashierSessionStatus::CLOSED) {
                throw new RuntimeException(sprintf('Drawer %s is already closed.', $session->session_number));
            }

            if ($session->status === CashierSessionStatus::PENDING_APPROVAL) {
                throw new RuntimeException(sprintf(
                    'Drawer %s has been counted and is waiting for a supervisor to approve its variance.',
                    $session->session_number,
                ));
            }

            $currency = (string) $session->currency_code;
            $computed = $this->totals->forSession($session);

            $declared = Money::of($declaredCashMinor, $currency);
            $expected = $computed['expectedCash'];
            $variance = $declared->minus($expected);

            $tolerance = (int) config('revenue.cash_variance_tolerance_minor', 0);
            $requiresApproval = abs($variance->minorUnits) > $tolerance;

            $session->declared_cash_minor = $declared->minorUnits;
            $session->expected_cash_minor = $expected->minorUnits;
            $session->variance_minor = $variance->minorUnits;
            $session->counted_at = now();
            $session->status = $requiresApproval
                ? CashierSessionStatus::PENDING_APPROVAL
                : CashierSessionStatus::CLOSED;

            if (! $requiresApproval) {
                $session->closed_at = now();
                $session->closed_by_user_id = $actorUserId;
            }

            $session->save();

            $this->auditRecorder->record(
                entityType: 'cashier_session',
                entityId: (string) $session->id,
                action: $requiresApproval ? 'counted_pending_approval' : 'closed',
                actorUserId: $actorUserId,
                amount: $variance,
                after: [
                    'declared' => $declared->toDecimalString(),
                    'expected' => $expected->toDecimalString(),
                    'variance' => $variance->toDecimalString(),
                    'status' => $session->status->value,
                ],
                cashierSessionId: (string) $session->id,
            );

            return [
                'session' => $session,
                'totals' => $computed,
                'requiresApproval' => $requiresApproval,
            ];
        });
    }
}
