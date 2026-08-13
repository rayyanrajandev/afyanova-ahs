<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingInvoicePaymentResponseTransformer
{
    public static function transform(array $payment): array
    {
        return [
            'id' => $payment['id'] ?? null,
            'billingInvoiceId' => $payment['billing_invoice_id'] ?? null,
            'recordedByUserId' => $payment['recorded_by_user_id'] ?? null,
            'paymentAt' => $payment['payment_at'] ?? null,
            'amount' => $payment['amount'] ?? null,
            'cumulativePaidAmount' => $payment['cumulative_paid_amount'] ?? null,
            'entryType' => $payment['entry_type'] ?? 'payment',
            'reversalOfPaymentId' => $payment['reversal_of_payment_id'] ?? null,
            'reversalReason' => $payment['reversal_reason'] ?? null,
            'approvalCaseReference' => $payment['approval_case_reference'] ?? null,
            'payerType' => $payment['payer_type'] ?? null,
            'paymentMethod' => $payment['payment_method'] ?? null,
            'paymentReference' => $payment['payment_reference'] ?? null,
            'sourceAction' => $payment['source_action'] ?? null,
            'note' => $payment['note'] ?? null,
            'createdAt' => $payment['created_at'] ?? null,
            'updatedAt' => $payment['updated_at'] ?? null,
        ];
    }
}
