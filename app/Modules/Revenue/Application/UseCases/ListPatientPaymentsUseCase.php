<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\PaymentStatus;
use App\Modules\Revenue\Domain\ValueObjects\RefundStatus;
use App\Modules\Revenue\Infrastructure\Models\PaymentModel;
use App\Modules\Revenue\Infrastructure\Models\RefundModel;

/**
 * What this patient has actually paid, so a refund can be raised against a
 * specific payment rather than against a balance.
 *
 * Reversed payments are included but marked: a cashier looking for "the one I
 * took by mistake" needs to see that it has already been undone, or they will
 * raise a refund for money that was never kept.
 *
 * Each row carries how much of it is already spoken for by earlier refunds,
 * because the refundable amount is what the counter needs and the alternative
 * is a rejection after the patient has been told a number.
 */
class ListPatientPaymentsUseCase
{
    private const LIMIT = 50;

    /**
     * @return array{data: array<int, array<string, mixed>>}
     */
    public function execute(string $patientId): array
    {
        $payments = PaymentModel::query()
            ->with('receipt')
            ->where('patient_id', $patientId)
            ->orderByDesc('received_at')
            ->limit(self::LIMIT)
            ->get();

        $refundedByPayment = RefundModel::query()
            ->whereIn('original_payment_id', $payments->pluck('id'))
            ->whereIn('status', [
                RefundStatus::REQUESTED->value,
                RefundStatus::APPROVED->value,
                RefundStatus::PAID->value,
            ])
            ->get()
            ->groupBy('original_payment_id')
            ->map(fn ($group): int => (int) $group->sum('amount_minor'));

        return [
            'data' => $payments->map(function (PaymentModel $payment) use ($refundedByPayment): array {
                $currency = (string) $payment->currency_code;
                $amount = Money::of((int) $payment->amount_minor, $currency);
                $alreadyRefunded = Money::of(
                    (int) ($refundedByPayment[$payment->id] ?? 0),
                    $currency,
                );

                $refundable = $payment->status === PaymentStatus::RECORDED
                    ? $amount->minus($alreadyRefunded)
                    : Money::zero($currency);

                return [
                    'id' => (string) $payment->id,
                    'paymentNumber' => (string) $payment->payment_number,
                    'receiptNumber' => $payment->receipt?->receipt_number,
                    'receiptId' => $payment->receipt?->id,
                    'method' => $payment->method->value,
                    'currencyCode' => $currency,
                    'amount' => $amount->toDecimalString(),
                    'alreadyRefunded' => $alreadyRefunded->toDecimalString(),
                    'refundable' => $refundable->toDecimalString(),
                    'isRefundable' => $refundable->isPositive(),
                    'status' => $payment->status->value,
                    'receivedAt' => $payment->received_at?->toIso8601String(),
                ];
            })->all(),
        ];
    }
}
