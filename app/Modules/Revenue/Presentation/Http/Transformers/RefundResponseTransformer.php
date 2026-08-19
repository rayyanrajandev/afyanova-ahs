<?php

namespace App\Modules\Revenue\Presentation\Http\Transformers;

use App\Modules\Revenue\Infrastructure\Models\RefundModel;

class RefundResponseTransformer
{
    /**
     * @return array<string, mixed>
     */
    public static function transform(RefundModel $refund): array
    {
        return [
            'id' => (string) $refund->id,
            'refundNumber' => (string) $refund->refund_number,
            'patientId' => (string) $refund->patient_id,
            'originalPaymentId' => (string) $refund->original_payment_id,
            'serviceChargeId' => $refund->service_charge_id,
            'currencyCode' => (string) $refund->currency_code,
            'amount' => $refund->amount()->toDecimalString(),
            'reason' => (string) $refund->reason,
            'status' => $refund->status->value,
            'requestedByUserId' => (int) $refund->requested_by_user_id,
            'requestedAt' => $refund->requested_at?->toIso8601String(),
            'approvedByUserId' => $refund->approved_by_user_id,
            'approvedAt' => $refund->approved_at?->toIso8601String(),
            'paidFromSessionId' => $refund->paid_from_session_id,
        ];
    }
}
