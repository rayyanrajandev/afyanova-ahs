<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Revenue\Domain\Services\DocumentNumberAllocatorInterface;
use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\PaymentStatus;
use App\Modules\Revenue\Domain\ValueObjects\RefundStatus;
use App\Modules\Revenue\Infrastructure\Models\PaymentModel;
use App\Modules\Revenue\Infrastructure\Models\RefundModel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Ask for money back on a payment that can no longer simply be reversed.
 *
 * Requesting and approving are separate acts by separate people. This use case
 * only records the request; nothing leaves the drawer until someone else
 * approves it.
 */
class RequestRefundUseCase
{
    public function __construct(
        private readonly DocumentNumberAllocatorInterface $numberAllocator,
        private readonly RevenueAuditRecorderInterface $auditRecorder,
    ) {}

    public function execute(
        string $paymentId,
        int $amountMinor,
        string $reason,
        int $requestedByUserId,
        ?string $serviceChargeId = null,
    ): RefundModel {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A refund request must say why.');
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('A refund must be a positive amount.');
        }

        return DB::transaction(function () use (
            $paymentId, $amountMinor, $reason, $requestedByUserId, $serviceChargeId
        ): RefundModel {
            $payment = PaymentModel::query()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->status !== PaymentStatus::RECORDED) {
                throw new RuntimeException(sprintf(
                    'Payment %s is %s — there is nothing to refund.',
                    $payment->payment_number,
                    $payment->status->value,
                ));
            }

            $alreadyRefunded = (int) RefundModel::query()
                ->where('original_payment_id', $payment->id)
                ->whereIn('status', [RefundStatus::REQUESTED->value, RefundStatus::APPROVED->value, RefundStatus::PAID->value])
                ->sum('amount_minor');

            if ($alreadyRefunded + $amountMinor > (int) $payment->amount_minor) {
                throw new RuntimeException(sprintf(
                    'Refunding %s would exceed what was paid on %s.',
                    Money::of($amountMinor, (string) $payment->currency_code)->toDecimalString(),
                    $payment->payment_number,
                ));
            }

            $amount = Money::of($amountMinor, (string) $payment->currency_code);

            $refund = RefundModel::query()->create([
                'tenant_id' => $payment->tenant_id,
                'facility_id' => $payment->facility_id,
                'refund_number' => $this->numberAllocator->allocate(
                    'refund', $payment->tenant_id, $payment->facility_id,
                ),
                'patient_id' => $payment->patient_id,
                'original_payment_id' => $payment->id,
                'service_charge_id' => $serviceChargeId,
                'currency_code' => $payment->currency_code,
                'amount_minor' => $amount->minorUnits,
                'reason' => $reason,
                'status' => RefundStatus::REQUESTED->value,
                'requested_by_user_id' => $requestedByUserId,
                'requested_at' => now(),
            ]);

            $this->auditRecorder->record(
                entityType: 'refund',
                entityId: (string) $refund->id,
                action: 'requested',
                actorUserId: $requestedByUserId,
                amount: $amount,
                after: [
                    'refundNumber' => (string) $refund->refund_number,
                    'paymentNumber' => (string) $payment->payment_number,
                ],
                reason: $reason,
            );

            return $refund;
        });
    }
}
