<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingRefundResponseTransformer
{
    public static function transform(array $refund): array
    {
        $patientDisplayName = collect([
            $refund['first_name'] ?? null,
            $refund['middle_name'] ?? null,
            $refund['last_name'] ?? null,
        ])->filter()->implode(' ');

        return [
            'id' => $refund['id'] ?? null,
            'billing_invoice_id' => $refund['billing_invoice_id'] ?? null,
            'billing_invoice_payment_id' => $refund['billing_invoice_payment_id'] ?? null,
            'patient_id' => $refund['patient_id'] ?? null,
            'refund_reason' => $refund['refund_reason'] ?? null,
            'refund_amount' => isset($refund['refund_amount']) ? (float) $refund['refund_amount'] : null,
            'refund_method' => $refund['refund_method'] ?? null,
            'mobile_money_provider' => $refund['mobile_money_provider'] ?? null,
            'mobile_money_reference' => $refund['mobile_money_reference'] ?? null,
            'card_reference' => $refund['card_reference'] ?? null,
            'check_number' => $refund['check_number'] ?? null,
            'requested_by_user_id' => $refund['requested_by_user_id'] ?? null,
            'requested_at' => $refund['requested_at'] ?? null,
            'approved_by_user_id' => $refund['approved_by_user_id'] ?? null,
            'approved_at' => $refund['approved_at'] ?? null,
            'processed_by_user_id' => $refund['processed_by_user_id'] ?? null,
            'processed_at' => $refund['processed_at'] ?? null,
            'refund_status' => $refund['refund_status'] ?? null,
            'rejection_reason' => $refund['rejection_reason'] ?? null,
            'notes' => $refund['notes'] ?? null,
            'created_at' => $refund['created_at'] ?? null,
            'updated_at' => $refund['updated_at'] ?? null,
            'invoice' => [
                'id' => $refund['billing_invoice_id'] ?? null,
                'invoice_number' => $refund['invoice_number'] ?? null,
                'currency_code' => $refund['invoice_currency_code'] ?? null,
                'status' => $refund['invoice_status'] ?? null,
                'total_amount' => isset($refund['invoice_total_amount']) ? (float) $refund['invoice_total_amount'] : null,
                'paid_amount' => isset($refund['invoice_paid_amount']) ? (float) $refund['invoice_paid_amount'] : null,
                'balance_amount' => isset($refund['invoice_balance_amount']) ? (float) $refund['invoice_balance_amount'] : null,
            ],
            'patient' => [
                'id' => $refund['patient_id'] ?? null,
                'patient_number' => $refund['patient_number'] ?? null,
                'display_name' => $patientDisplayName !== '' ? $patientDisplayName : ($refund['patient_number'] ?? null),
                'phone' => $refund['patient_phone'] ?? null,
            ],
        ];
    }
}
