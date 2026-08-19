<?php

namespace App\Modules\Revenue\Presentation\Http\Transformers;

use App\Modules\Revenue\Infrastructure\Models\ReceiptModel;

class ReceiptResponseTransformer
{
    /**
     * @return array<string, mixed>
     */
    public static function transform(ReceiptModel $receipt): array
    {
        return [
            'id' => (string) $receipt->id,
            'receiptNumber' => (string) $receipt->receipt_number,
            'paymentId' => (string) $receipt->payment_id,
            'patientId' => (string) $receipt->patient_id,
            'currencyCode' => (string) $receipt->currency_code,
            'total' => $receipt->total()->toDecimalString(),
            'issuedAt' => $receipt->issued_at?->toIso8601String(),
            // The lines exactly as first printed. A reprint must reproduce the
            // paper the patient holds, not re-derive it from charges that may
            // have moved on since.
            'snapshot' => $receipt->snapshot,
            'fiscalStatus' => (string) $receipt->fiscal_status,
            'fiscalReference' => $receipt->fiscal_reference,
            'reprintCount' => (int) $receipt->reprint_count,
        ];
    }
}
