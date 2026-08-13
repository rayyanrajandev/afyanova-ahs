<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingPaymentPlanResponseTransformer
{
    public static function transform(array $plan): array
    {
        $displayName = collect([
            $plan['first_name'] ?? null,
            $plan['middle_name'] ?? null,
            $plan['last_name'] ?? null,
        ])->filter()->implode(' ');

        return [
            'id' => $plan['id'] ?? null,
            'tenantId' => $plan['tenant_id'] ?? null,
            'facilityId' => $plan['facility_id'] ?? null,
            'patientId' => $plan['patient_id'] ?? null,
            'billingInvoiceId' => $plan['billing_invoice_id'] ?? null,
            'cashBillingAccountId' => $plan['cash_billing_account_id'] ?? null,
            'planNumber' => $plan['plan_number'] ?? null,
            'planName' => $plan['plan_name'] ?? null,
            'currencyCode' => $plan['currency_code'] ?? null,
            'totalAmount' => isset($plan['total_amount']) ? (float) $plan['total_amount'] : null,
            'downPaymentAmount' => isset($plan['down_payment_amount']) ? (float) $plan['down_payment_amount'] : null,
            'financedAmount' => isset($plan['financed_amount']) ? (float) $plan['financed_amount'] : null,
            'paidAmount' => isset($plan['paid_amount']) ? (float) $plan['paid_amount'] : null,
            'balanceAmount' => isset($plan['balance_amount']) ? (float) $plan['balance_amount'] : null,
            'installmentCount' => $plan['installment_count'] ?? null,
            'installmentFrequency' => $plan['installment_frequency'] ?? null,
            'installmentIntervalDays' => $plan['installment_interval_days'] ?? null,
            'firstDueDate' => $plan['first_due_date'] ?? null,
            'nextDueDate' => $plan['next_due_date'] ?? null,
            'lastPaymentAt' => $plan['last_payment_at'] ?? null,
            'status' => $plan['status'] ?? null,
            'termsAndNotes' => $plan['terms_and_notes'] ?? null,
            'metadata' => $plan['metadata'] ?? null,
            'invoiceNumber' => $plan['invoice_number'] ?? null,
            'patient' => [
                'patientNumber' => $plan['patient_number'] ?? null,
                'displayName' => $displayName !== '' ? $displayName : ($plan['patient_number'] ?? null),
            ],
            'installments' => array_map(
                [BillingPaymentPlanInstallmentResponseTransformer::class, 'transform'],
                is_array($plan['installments'] ?? null) ? $plan['installments'] : [],
            ),
            'createdAt' => $plan['created_at'] ?? null,
            'updatedAt' => $plan['updated_at'] ?? null,
        ];
    }
}
