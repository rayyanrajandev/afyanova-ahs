<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingInvoiceResponseTransformer
{
    public static function transform(array $invoice): array
    {
        $pricingContext = is_array($invoice['pricing_context'] ?? null)
            ? $invoice['pricing_context']
            : null;

        return [
            'id' => $invoice['id'] ?? null,
            'invoiceNumber' => $invoice['invoice_number'] ?? null,
            'patientId' => $invoice['patient_id'] ?? null,
            'encounterId' => $invoice['encounter_id'] ?? null,
            'admissionId' => $invoice['admission_id'] ?? null,
            'appointmentId' => $invoice['appointment_id'] ?? null,
            'billingPayerContractId' => $invoice['billing_payer_contract_id'] ?? null,
            'issuedByUserId' => $invoice['issued_by_user_id'] ?? null,
            'invoiceDate' => $invoice['invoice_date'] ?? null,
            'currencyCode' => $invoice['currency_code'] ?? null,
            'subtotalAmount' => $invoice['subtotal_amount'] ?? null,
            'discountAmount' => $invoice['discount_amount'] ?? null,
            'taxAmount' => $invoice['tax_amount'] ?? null,
            'totalAmount' => $invoice['total_amount'] ?? null,
            'paidAmount' => $invoice['paid_amount'] ?? null,
            'lastPaymentAt' => $invoice['last_payment_at'] ?? null,
            'lastPaymentPayerType' => $invoice['last_payment_payer_type'] ?? null,
            'lastPaymentMethod' => $invoice['last_payment_method'] ?? null,
            'lastPaymentReference' => $invoice['last_payment_reference'] ?? null,
            'balanceAmount' => $invoice['balance_amount'] ?? null,
            'paymentDueAt' => $invoice['payment_due_at'] ?? null,
            'notes' => $invoice['notes'] ?? null,
            'lineItems' => $invoice['line_items'] ?? null,
            'pricingMode' => $invoice['pricing_mode'] ?? null,
            'pricingContext' => $pricingContext,
            'priceOverrideSummary' => is_array($pricingContext['priceOverrideSummary'] ?? null)
                ? $pricingContext['priceOverrideSummary']
                : null,
            'authorizationSummary' => is_array($pricingContext['authorizationSummary'] ?? null)
                ? $pricingContext['authorizationSummary']
                : null,
            'coverageSummary' => is_array($pricingContext['coverageSummary'] ?? null)
                ? $pricingContext['coverageSummary']
                : null,
            'visitCoverage' => is_array($pricingContext['visitCoverage'] ?? null)
                ? $pricingContext['visitCoverage']
                : null,
            'payerSummary' => is_array($pricingContext['payerSummary'] ?? null)
                ? $pricingContext['payerSummary']
                : null,
            'claimReadiness' => is_array($pricingContext['claimReadiness'] ?? null)
                ? $pricingContext['claimReadiness']
                : null,
            'consultationReviewDiscount' => is_array($pricingContext['consultationReviewDiscount'] ?? null)
                ? $pricingContext['consultationReviewDiscount']
                : null,
            'status' => $invoice['status'] ?? null,
            'statusReason' => $invoice['status_reason'] ?? null,
            'createdAt' => $invoice['created_at'] ?? null,
            'updatedAt' => $invoice['updated_at'] ?? null,
        ];
    }
}
