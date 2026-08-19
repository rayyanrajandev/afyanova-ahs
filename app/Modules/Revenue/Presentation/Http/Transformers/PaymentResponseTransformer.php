<?php

namespace App\Modules\Revenue\Presentation\Http\Transformers;

use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Infrastructure\Models\PaymentAllocationModel;
use App\Modules\Revenue\Infrastructure\Models\PaymentModel;

class PaymentResponseTransformer
{
    /**
     * @return array<string, mixed>
     */
    public static function transform(PaymentModel $payment): array
    {
        $currency = (string) $payment->currency_code;

        return [
            'id' => (string) $payment->id,
            'paymentNumber' => (string) $payment->payment_number,
            'patientId' => (string) $payment->patient_id,
            'cashierSessionId' => $payment->cashier_session_id,
            'method' => $payment->method->value,
            'currencyCode' => $currency,
            'amount' => Money::of((int) $payment->amount_minor, $currency)->toDecimalString(),
            'tendered' => $payment->tendered_amount_minor === null
                ? null
                : Money::of((int) $payment->tendered_amount_minor, $currency)->toDecimalString(),
            'change' => $payment->change_amount_minor === null
                ? null
                : Money::of((int) $payment->change_amount_minor, $currency)->toDecimalString(),
            'status' => $payment->status->value,
            'receivedAt' => $payment->received_at?->toIso8601String(),
            'reversalReason' => $payment->reversal_reason,
            'allocations' => $payment->allocations
                ->map(static fn (PaymentAllocationModel $a): array => [
                    'serviceChargeId' => (string) $a->service_charge_id,
                    'amount' => $a->amount()->toDecimalString(),
                ])
                ->values()
                ->all(),
            'receipt' => $payment->receipt === null
                ? null
                : ReceiptResponseTransformer::transform($payment->receipt),
        ];
    }
}
