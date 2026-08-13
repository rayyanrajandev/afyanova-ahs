<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingCorporateInvoiceRunResponseTransformer
{
    public static function transform(array $run): array
    {
        return [
            'id' => $run['id'] ?? null,
            'billingCorporateAccountId' => $run['billing_corporate_account_id'] ?? null,
            'runNumber' => $run['run_number'] ?? null,
            'billingPeriodStart' => $run['billing_period_start'] ?? null,
            'billingPeriodEnd' => $run['billing_period_end'] ?? null,
            'issueDate' => $run['issue_date'] ?? null,
            'dueDate' => $run['due_date'] ?? null,
            'currencyCode' => $run['currency_code'] ?? null,
            'invoiceCount' => $run['invoice_count'] ?? null,
            'totalAmount' => isset($run['total_amount']) ? (float) $run['total_amount'] : null,
            'paidAmount' => isset($run['paid_amount']) ? (float) $run['paid_amount'] : null,
            'balanceAmount' => isset($run['balance_amount']) ? (float) $run['balance_amount'] : null,
            'lastPaymentAt' => $run['last_payment_at'] ?? null,
            'status' => $run['status'] ?? null,
            'notes' => $run['notes'] ?? null,
            'metadata' => $run['metadata'] ?? null,
            'invoices' => array_values($run['invoices'] ?? []),
            'payments' => array_values($run['payments'] ?? []),
            'createdAt' => $run['created_at'] ?? null,
            'updatedAt' => $run['updated_at'] ?? null,
        ];
    }
}
