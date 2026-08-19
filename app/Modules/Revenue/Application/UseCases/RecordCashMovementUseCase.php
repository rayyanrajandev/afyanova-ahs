<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\CashMovementReason;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionModel;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionMovementModel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cash in or out of a drawer other than by taking payment.
 *
 * Without this the expected total is wrong from the first time anyone banks a
 * float, and every close after that reports a variance nobody can explain —
 * which trains people to sign off variances, defeating the control.
 */
class RecordCashMovementUseCase
{
    public function __construct(
        private readonly RevenueAuditRecorderInterface $auditRecorder,
    ) {}

    public function execute(
        string $cashierSessionId,
        CashMovementReason $reason,
        int $amountMinor,
        int $actorUserId,
        ?string $note = null,
    ): CashierSessionMovementModel {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('A cash movement must be a positive amount.');
        }

        return DB::transaction(function () use (
            $cashierSessionId, $reason, $amountMinor, $actorUserId, $note
        ): CashierSessionMovementModel {
            $session = CashierSessionModel::query()->lockForUpdate()->findOrFail($cashierSessionId);

            if (! $session->status->acceptsPayments()) {
                throw new RuntimeException('That drawer is closed; cash cannot move through it.');
            }

            $amount = Money::of($amountMinor, (string) $session->currency_code);

            $movement = CashierSessionMovementModel::query()->create([
                'tenant_id' => $session->tenant_id,
                'facility_id' => $session->facility_id,
                'cashier_session_id' => $session->id,
                'direction' => $reason->direction(),
                'reason' => $reason->value,
                'currency_code' => $session->currency_code,
                'amount_minor' => $amount->minorUnits,
                'note' => $note,
                'actor_user_id' => $actorUserId,
                'occurred_at' => now(),
            ]);

            $this->auditRecorder->record(
                entityType: 'cashier_session',
                entityId: (string) $session->id,
                action: 'cash_'.$reason->direction(),
                actorUserId: $actorUserId,
                amount: $amount,
                after: ['reason' => $reason->value, 'note' => $note],
                cashierSessionId: (string) $session->id,
            );

            return $movement;
        });
    }
}
