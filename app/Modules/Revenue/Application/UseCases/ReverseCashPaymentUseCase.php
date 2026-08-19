<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Revenue\Domain\Services\DocumentNumberAllocatorInterface;
use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\PaymentStatus;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\PaymentModel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Undo a payment taken in error, within the session that took it.
 *
 * The original payment, its allocations and its receipt are left exactly as
 * issued — a reversal is a second, linked payment row. Editing the first would
 * destroy the only record of what the patient was handed, which is the one
 * document they can produce in a dispute.
 *
 * Restricted to the open session on purpose. Once a drawer is counted and
 * closed, its cash total is a signed figure; changing it afterwards is a
 * refund, with a second person's approval, not a correction.
 */
class ReverseCashPaymentUseCase
{
    public function __construct(
        private readonly DocumentNumberAllocatorInterface $numberAllocator,
        private readonly RevenueAuditRecorderInterface $auditRecorder,
    ) {}

    public function execute(string $paymentId, string $reason, int $actorUserId): PaymentModel
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('A reversal must say why.');
        }

        return DB::transaction(function () use ($paymentId, $reason, $actorUserId): PaymentModel {
            $payment = PaymentModel::query()
                ->with(['allocations.serviceCharge', 'session'])
                ->lockForUpdate()
                ->findOrFail($paymentId);

            if ($payment->status !== PaymentStatus::RECORDED) {
                throw new RuntimeException(sprintf(
                    'Payment %s is %s and cannot be reversed.',
                    $payment->payment_number,
                    $payment->status->value,
                ));
            }

            $session = $payment->session;

            if ($session === null || ! $session->status->acceptsPayments()) {
                throw new RuntimeException(
                    'That payment belongs to a closed drawer. Raise a refund instead of a reversal.',
                );
            }

            foreach ($payment->allocations as $allocation) {
                $charge = $allocation->serviceCharge;

                if ($charge === null) {
                    continue;
                }

                $charge->allocated_amount_minor = max(
                    0,
                    $charge->allocated_amount_minor - $allocation->amount_minor,
                );

                // Releasing the money releases the authorization with it —
                // the service must not be delivered on a payment that no
                // longer exists.
                if ($charge->status === ServiceChargeStatus::AUTHORIZED) {
                    $charge->status = ServiceChargeStatus::PENDING_PAYMENT;
                    $charge->authorization_basis = null;
                    $charge->authorized_at = null;
                    $charge->authorized_by_user_id = null;
                    $charge->authorization_reference = null;
                }

                $charge->save();
                $allocation->delete();
            }

            $reversal = PaymentModel::query()->create([
                'tenant_id' => $payment->tenant_id,
                'facility_id' => $payment->facility_id,
                'payment_number' => $this->numberAllocator->allocate(
                    'payment', $payment->tenant_id, $payment->facility_id,
                ),
                'patient_id' => $payment->patient_id,
                'cashier_session_id' => $session->id,
                'method' => $payment->method->value,
                'currency_code' => $payment->currency_code,
                'amount_minor' => -$payment->amount_minor,
                'allocated_amount_minor' => 0,
                'status' => PaymentStatus::REVERSAL->value,
                'received_at' => now(),
                'received_by_user_id' => $actorUserId,
                'reversal_of_payment_id' => $payment->id,
                'reversal_reason' => $reason,
                'idempotency_key' => 'reversal:'.$payment->id,
            ]);

            $payment->status = PaymentStatus::REVERSED;
            $payment->allocated_amount_minor = 0;
            $payment->reversed_at = now();
            $payment->reversed_by_user_id = $actorUserId;
            $payment->reversal_reason = $reason;
            $payment->save();

            $this->auditRecorder->record(
                entityType: 'payment',
                entityId: (string) $payment->id,
                action: 'reversed',
                actorUserId: $actorUserId,
                amount: $payment->amount(),
                before: ['status' => PaymentStatus::RECORDED->value],
                after: [
                    'status' => PaymentStatus::REVERSED->value,
                    'reversalPaymentNumber' => (string) $reversal->payment_number,
                ],
                reason: $reason,
                cashierSessionId: (string) $session->id,
            );

            return $reversal;
        });
    }
}
