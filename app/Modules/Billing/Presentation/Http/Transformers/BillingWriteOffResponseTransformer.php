<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingWriteOffResponseTransformer
{
    public static function transform(array $writeOff): array
    {
        return [
            'id' => $writeOff['id'] ?? null,
            'billingInvoiceId' => $writeOff['billing_invoice_id'] ?? null,
            'patientId' => $writeOff['patient_id'] ?? null,
            'amount' => (float) ($writeOff['amount'] ?? 0),
            'reason' => $writeOff['reason'] ?? null,
            'status' => $writeOff['status'] ?? null,
            'approvedByUserId' => $writeOff['approved_by_user_id'] ?? null,
            'approvedAt' => $writeOff['approved_at'] ?? null,
            'notes' => $writeOff['notes'] ?? null,
            'createdByUserId' => $writeOff['created_by_user_id'] ?? null,
            'createdAt' => $writeOff['created_at'] ?? null,
            'updatedAt' => $writeOff['updated_at'] ?? null,
        ];
    }
}
