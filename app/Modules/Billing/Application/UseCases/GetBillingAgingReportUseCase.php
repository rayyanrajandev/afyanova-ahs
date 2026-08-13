<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use Carbon\Carbon;

class GetBillingAgingReportUseCase
{
    public function execute(
        ?string $currencyCode = null,
        ?string $asOfDate = null,
        ?string $departmentFilter = null,
    ): array {
        $asOf = $asOfDate !== null ? Carbon::parse($asOfDate)->endOfDay() : Carbon::now()->endOfDay();

        $query = BillingInvoiceModel::query()
            ->whereNotIn('status', ['draft', 'paid', 'cancelled'])
            ->where('balance_amount', '>', 0);

        if ($currencyCode !== null) {
            $query->where('currency_code', strtoupper($currencyCode));
        }

        if ($departmentFilter !== null) {
            $query->where('line_items', 'like', "%\"department\":\"{$departmentFilter}\"%");
        }

        $invoices = $query->get();

        $buckets = [
            'current' => ['label' => 'Current', 'daysMin' => 0, 'daysMax' => 30, 'invoices' => []],
            '31_60' => ['label' => '31-60 Days', 'daysMin' => 31, 'daysMax' => 60, 'invoices' => []],
            '61_90' => ['label' => '61-90 Days', 'daysMin' => 61, 'daysMax' => 90, 'invoices' => []],
            '90_plus' => ['label' => '90+ Days', 'daysMin' => 91, 'daysMax' => null, 'invoices' => []],
        ];

        foreach ($invoices as $invoice) {
            $invoiceDate = Carbon::parse($invoice->invoice_date);
            $ageInDays = (int) $invoiceDate->diffInDays($asOf);

            $age = $invoiceDate->diffInDays($asOf);

            $bucketKey = '90_plus';
            if ($age <= 30) {
                $bucketKey = 'current';
            } elseif ($age <= 60) {
                $bucketKey = '31_60';
            } elseif ($age <= 90) {
                $bucketKey = '61_90';
            }

            $buckets[$bucketKey]['invoices'][] = [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'patient_id' => $invoice->patient_id,
                'invoice_date' => $invoice->invoice_date->toISOString(),
                'total_amount' => (float) $invoice->total_amount,
                'balance_amount' => (float) $invoice->balance_amount,
                'age_days' => $ageInDays,
                'status' => $invoice->status,
                'currency_code' => $invoice->currency_code,
            ];
        }

        $totalCount = 0;
        $totalBalance = 0.0;

        $bucketResults = [];

        foreach ($buckets as $key => $bucket) {
            $count = count($bucket['invoices']);
            $balance = array_sum(array_column($bucket['invoices'], 'balance_amount'));

            $totalCount += $count;
            $totalBalance += $balance;

            $bucketResults[] = [
                'key' => $key,
                'label' => $bucket['label'],
                'daysMin' => $bucket['daysMin'],
                'daysMax' => $bucket['daysMax'],
                'count' => $count,
                'totalBalance' => round($balance, 2),
                'invoices' => $bucket['invoices'],
            ];
        }

        return [
            'asOfDate' => $asOf->toISOString(),
            'currencyCode' => $currencyCode,
            'departmentFilter' => $departmentFilter,
            'buckets' => $bucketResults,
            'totals' => [
                'count' => $totalCount,
                'totalBalance' => round($totalBalance, 2),
            ],
        ];
    }
}
