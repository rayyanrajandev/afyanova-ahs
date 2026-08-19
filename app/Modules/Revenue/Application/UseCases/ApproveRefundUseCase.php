<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use App\Modules\Revenue\Domain\ValueObjects\RefundStatus;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionModel;
use App\Modules\Revenue\Infrastructure\Models\RefundModel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A second person approves a refund, and the money leaves a named drawer.
 *
 * Paying out of a specific session is what makes the refund show up in that
 * session's expected cash — a refund settled "from the facility" in the
 * abstract would leave the till short with nothing to point at.
 */
class ApproveRefundUseCase
{
    public function __construct(
        private readonly RevenueAuditRecorderInterface $auditRecorder,
    ) {}

    public function execute(
        string $refundId,
        int $approverUserId,
        string $paidFromSessionId,
        ?string $note = null,
    ): RefundModel {
        return DB::transaction(function () use ($refundId, $approverUserId, $paidFromSessionId, $note): RefundModel {
            $refund = RefundModel::query()->lockForUpdate()->findOrFail($refundId);

            if ($refund->status !== RefundStatus::REQUESTED) {
                throw new RuntimeException(sprintf(
                    'Refund %s is %s and cannot be approved.',
                    $refund->refund_number,
                    $refund->status->value,
                ));
            }

            // Segregation of duties. Also a database constraint, so a code path
            // that forgets this check still cannot write the row.
            if ((int) $refund->requested_by_user_id === $approverUserId) {
                throw new RuntimeException(
                    'A refund cannot be approved by the person who requested it.',
                );
            }

            $session = CashierSessionModel::query()->lockForUpdate()->findOrFail($paidFromSessionId);

            if ($session->status !== CashierSessionStatus::OPEN) {
                throw new RuntimeException('Refunds must be paid out of an open drawer.');
            }

            $refund->status = RefundStatus::PAID;
            $refund->approved_by_user_id = $approverUserId;
            $refund->approved_at = now();
            $refund->approval_note = $note;
            $refund->paid_from_session_id = $session->id;
            $refund->paid_by_user_id = $approverUserId;
            $refund->paid_at = now();
            $refund->save();

            $this->auditRecorder->record(
                entityType: 'refund',
                entityId: (string) $refund->id,
                action: 'approved_and_paid',
                actorUserId: $approverUserId,
                amount: $refund->amount(),
                before: ['status' => RefundStatus::REQUESTED->value],
                after: [
                    'status' => RefundStatus::PAID->value,
                    'paidFromSession' => (string) $session->session_number,
                ],
                reason: $note,
                cashierSessionId: (string) $session->id,
            );

            return $refund;
        });
    }
}
