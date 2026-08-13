<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingPaymentPlanInstallmentResponseTransformer
{
    public static function transform(array $installment): array
    {
        return [
            'id' => $installment['id'] ?? null,
            'billingPaymentPlanId' => $installment['billing_payment_plan_id'] ?? null,
            'installmentNumber' => $installment['installment_number'] ?? null,
            'dueDate' => $installment['due_date'] ?? null,
            'scheduledAmount' => isset($installment['scheduled_amount']) ? (float) $installment['scheduled_amount'] : null,
            'paidAmount' => isset($installment['paid_amount']) ? (float) $installment['paid_amount'] : null,
            'outstandingAmount' => isset($installment['outstanding_amount']) ? (float) $installment['outstanding_amount'] : null,
            'paidAt' => $installment['paid_at'] ?? null,
            'status' => $installment['status'] ?? null,
            'sourceBillingInvoicePaymentId' => $installment['source_billing_invoice_payment_id'] ?? null,
            'sourceCashBillingPaymentId' => $installment['source_cash_billing_payment_id'] ?? null,
            'notes' => $installment['notes'] ?? null,
        ];
    }
}
