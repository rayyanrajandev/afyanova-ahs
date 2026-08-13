<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingPatientWorkspaceResponseTransformer
{
    public static function transform(array $workspace): array
    {
        $patient = is_array($workspace['patient'] ?? null) ? $workspace['patient'] : null;
        $invoices = is_array($workspace['invoices'] ?? null) ? $workspace['invoices'] : [];
        $summary = is_array($workspace['summary'] ?? null) ? $workspace['summary'] : [];

        return [
            'patient' => $patient !== null ? self::transformPatientSummary($patient) : null,
            'invoices' => array_map(
                static fn (array $invoice): array => BillingInvoiceResponseTransformer::transform($invoice),
                $invoices,
            ),
            'summary' => [
                'totalBilled' => (float) ($summary['totalBilled'] ?? 0),
                'totalPaid' => (float) ($summary['totalPaid'] ?? 0),
                'totalUnpaid' => (float) ($summary['totalUnpaid'] ?? 0),
                'invoiceCount' => (int) ($summary['invoiceCount'] ?? 0),
                'unpaidInvoiceCount' => (int) ($summary['unpaidInvoiceCount'] ?? 0),
            ],
        ];
    }

    private static function transformPatientSummary(array $patient): array
    {
        return [
            'id' => $patient['id'] ?? null,
            'patientNumber' => $patient['patient_number'] ?? null,
            'firstName' => $patient['first_name'] ?? null,
            'middleName' => $patient['middle_name'] ?? null,
            'lastName' => $patient['last_name'] ?? null,
            'phone' => $patient['phone'] ?? null,
            'dateOfBirth' => $patient['date_of_birth'] ?? null,
            'gender' => $patient['gender'] ?? null,
        ];
    }
}
