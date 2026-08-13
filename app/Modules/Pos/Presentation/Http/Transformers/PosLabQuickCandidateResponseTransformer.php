<?php

namespace App\Modules\Pos\Presentation\Http\Transformers;

class PosLabQuickCandidateResponseTransformer
{
    public static function transform(array $candidate): array
    {
        return [
            'id' => $candidate['id'] ?? null,
            'sourceKind' => $candidate['source_kind'] ?? null,
            'orderNumber' => $candidate['order_number'] ?? null,
            'patientId' => $candidate['patient_id'] ?? null,
            'patientNumber' => $candidate['patient_number'] ?? null,
            'patientName' => $candidate['patient_name'] ?? null,
            'appointmentId' => $candidate['appointment_id'] ?? null,
            'admissionId' => $candidate['admission_id'] ?? null,
            'testCode' => $candidate['test_code'] ?? null,
            'testName' => $candidate['test_name'] ?? null,
            'serviceCode' => $candidate['service_code'] ?? null,
            'serviceName' => $candidate['service_name'] ?? null,
            'unit' => $candidate['unit'] ?? null,
            'sourceStatus' => $candidate['source_status'] ?? null,
            'orderedAt' => $candidate['ordered_at'] ?? null,
            'resultedAt' => $candidate['resulted_at'] ?? null,
            'performedAt' => $candidate['performed_at'] ?? null,
            'currencyCode' => $candidate['currency_code'] ?? null,
            'unitPrice' => $candidate['unit_price'] ?? null,
            'lineTotal' => $candidate['line_total'] ?? null,
            'pricingStatus' => $candidate['pricing_status'] ?? null,
            'pricingSource' => $candidate['pricing_source'] ?? null,
            'pricingSourceId' => $candidate['pricing_source_id'] ?? null,
            'alreadyInvoiced' => (bool) ($candidate['already_invoiced'] ?? false),
            'invoiceId' => $candidate['invoice_id'] ?? null,
            'invoiceNumber' => $candidate['invoice_number'] ?? null,
            'invoiceStatus' => $candidate['invoice_status'] ?? null,
            'alreadySettled' => (bool) ($candidate['already_settled'] ?? false),
            'settledSaleId' => $candidate['settled_sale_id'] ?? null,
            'settledSaleNumber' => $candidate['settled_sale_number'] ?? null,
            'settledReceiptNumber' => $candidate['settled_receipt_number'] ?? null,
            'settledSoldAt' => $candidate['settled_sold_at'] ?? null,
            'metadata' => is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : null,
        ];
    }
}
