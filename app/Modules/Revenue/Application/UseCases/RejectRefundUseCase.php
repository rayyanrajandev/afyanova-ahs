<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\RefundStatus;
use App\Modules\Revenue\Infrastructure\Models\RefundModel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Decline a refund request.
 *
 * The counterpart to approval, and not optional: a queue a supervisor can only
 * say yes to is not a review. A declined request stays on the record with its
 * reason — the patient was told something, and what they were told needs to be
 * recoverable.
 */
class RejectRefundUseCase
{
    public function __construct(
        private readonly RevenueAuditRecorderInterface $auditRecorder,
    ) {}

    public function execute(string $refundId, int $rejectedByUserId, string $reason): RefundModel
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Declining a refund must say why.');
        }

        return DB::transaction(function () use ($refundId, $rejectedByUserId, $reason): RefundModel {
            $refund = RefundModel::query()->lockForUpdate()->findOrFail($refundId);

            if ($refund->status !== RefundStatus::REQUESTED) {
                throw new RuntimeException(sprintf(
                    'Refund %s is %s and cannot be declined.',
                    $refund->refund_number,
                    $refund->status->value,
                ));
            }

            // Same segregation of duties as approval: whoever asked cannot be
            // the one who rules on it, either way.
            if ((int) $refund->requested_by_user_id === $rejectedByUserId) {
                throw new RuntimeException(
                    'A refund cannot be declined by the person who requested it.',
                );
            }

            $refund->status = RefundStatus::REJECTED;
            $refund->rejected_by_user_id = $rejectedByUserId;
            $refund->rejected_at = now();
            $refund->rejection_reason = $reason;
            $refund->save();

            $this->auditRecorder->record(
                entityType: 'refund',
                entityId: (string) $refund->id,
                action: 'rejected',
                actorUserId: $rejectedByUserId,
                amount: $refund->amount(),
                before: ['status' => RefundStatus::REQUESTED->value],
                after: ['status' => RefundStatus::REJECTED->value],
                reason: $reason,
            );

            return $refund;
        });
    }
}
