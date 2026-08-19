<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Infrastructure\Models\ReceiptModel;
use Illuminate\Support\Facades\DB;

/**
 * Re-issue a receipt, and record that it happened.
 *
 * The count is not bookkeeping for its own sake: repeated reprints against one
 * payment, or a cashier who reprints far more than their colleagues, is a
 * recognised fraud signal. A reprint that left no trace would remove the only
 * way to see it.
 */
class ReprintReceiptUseCase
{
    public function __construct(
        private readonly RevenueAuditRecorderInterface $auditRecorder,
    ) {}

    public function execute(string $receiptId, int $actorUserId, ?string $reason = null): ReceiptModel
    {
        return DB::transaction(function () use ($receiptId, $actorUserId, $reason): ReceiptModel {
            $receipt = ReceiptModel::query()->lockForUpdate()->findOrFail($receiptId);

            $receipt->reprint_count = (int) $receipt->reprint_count + 1;
            $receipt->last_reprinted_at = now();
            $receipt->save();

            $this->auditRecorder->record(
                entityType: 'receipt',
                entityId: (string) $receipt->id,
                action: 'reprinted',
                actorUserId: $actorUserId,
                amount: $receipt->total(),
                after: [
                    'receiptNumber' => (string) $receipt->receipt_number,
                    'reprintCount' => $receipt->reprint_count,
                ],
                reason: $reason,
            );

            return $receipt;
        });
    }
}
