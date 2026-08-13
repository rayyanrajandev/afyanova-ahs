<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingAgingReportResponseTransformer
{
    public static function transform(array $report): array
    {
        $buckets = array_map(function (array $bucket): array {
            return [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'daysMin' => $bucket['daysMin'],
                'daysMax' => $bucket['daysMax'],
                'count' => $bucket['count'],
                'totalBalance' => (float) ($bucket['totalBalance'] ?? 0),
                'invoices' => array_map(function (array $invoice): array {
                    return [
                        'id' => $invoice['id'],
                        'invoiceNumber' => $invoice['invoice_number'],
                        'patientId' => $invoice['patient_id'],
                        'invoiceDate' => $invoice['invoice_date'],
                        'totalAmount' => (float) ($invoice['total_amount'] ?? 0),
                        'balanceAmount' => (float) ($invoice['balance_amount'] ?? 0),
                        'ageDays' => $invoice['age_days'],
                        'status' => $invoice['status'],
                        'currencyCode' => $invoice['currency_code'],
                    ];
                }, $bucket['invoices']),
            ];
        }, $report['buckets']);

        return [
            'asOfDate' => $report['asOfDate'],
            'currencyCode' => $report['currencyCode'],
            'departmentFilter' => $report['departmentFilter'],
            'buckets' => $buckets,
            'totals' => [
                'count' => $report['totals']['count'],
                'totalBalance' => (float) ($report['totals']['totalBalance'] ?? 0),
            ],
        ];
    }
}
