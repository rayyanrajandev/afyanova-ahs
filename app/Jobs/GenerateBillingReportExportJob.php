<?php

namespace App\Jobs;

use App\Modules\Billing\Application\UseCases\GetBillingAgingReportUseCase;
use App\Modules\Billing\Infrastructure\Models\BillingReportExportJobModel;
use App\Support\Exports\BrandedCsvExportManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * GetBillingAgingReportUseCase does an unbounded ->get() over every open
 * invoice and buckets it in PHP — fine for a quick on-screen view, but a
 * genuine "grows with data" risk for a full CSV export. This job runs that
 * export off the request cycle, following GenerateAuditExportCsvJob's
 * queued->processing idempotency-guard convention.
 */
class GenerateBillingReportExportJob implements ShouldQueue
{
    use Queueable;

    private const AGING_COLUMNS = [
        'bucket', 'invoiceNumber', 'patientId', 'invoiceDate',
        'totalAmount', 'balanceAmount', 'ageDays', 'status', 'currencyCode',
    ];

    public function __construct(
        private readonly string $reportExportJobId,
    ) {}

    public function handle(
        GetBillingAgingReportUseCase $agingReportUseCase,
        BrandedCsvExportManager $csvExports,
    ): void {
        $reportExportJob = BillingReportExportJobModel::query()->find($this->reportExportJobId);
        if (! $reportExportJob) {
            return;
        }

        $updated = BillingReportExportJobModel::query()
            ->whereKey($reportExportJob->getKey())
            ->where('status', 'queued')
            ->update([
                'status' => 'processing',
                'started_at' => now(),
                'error_message' => null,
                'failed_at' => null,
            ]);

        if ($updated === 0) {
            return;
        }

        $reportExportJob->refresh();
        $filters = is_array($reportExportJob->filters) ? $reportExportJob->filters : [];

        try {
            if ($reportExportJob->report_type !== 'aging') {
                throw new \RuntimeException('Unsupported billing report export type.');
            }

            $report = $agingReportUseCase->execute(
                currencyCode: $filters['currencyCode'] ?? null,
                asOfDate: $filters['asOfDate'] ?? null,
                departmentFilter: $filters['departmentFilter'] ?? null,
            );

            $fileName = $csvExports->makeBrandedFilename(
                sprintf('billing_aging_report_%s', now()->format('Ymd_His'))
            );
            $filePath = sprintf('billing-report-exports/%s.csv', $reportExportJob->getKey());

            $rowCount = $csvExports->writeFlatCsvFile(
                filePath: $filePath,
                columns: self::AGING_COLUMNS,
                rows: $this->agingRows($report),
            );

            $reportExportJob->fill([
                'status' => 'completed',
                'file_path' => $filePath,
                'file_name' => $fileName,
                'row_count' => $rowCount,
                'completed_at' => now(),
                'failed_at' => null,
                'error_message' => null,
            ])->save();
        } catch (\Throwable $exception) {
            $reportExportJob->fill([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return iterable<array<int, string>>
     */
    private function agingRows(array $report): iterable
    {
        foreach ($report['buckets'] ?? [] as $bucket) {
            foreach ($bucket['invoices'] ?? [] as $invoice) {
                yield [
                    (string) ($bucket['label'] ?? ''),
                    (string) ($invoice['invoice_number'] ?? ''),
                    (string) ($invoice['patient_id'] ?? ''),
                    (string) ($invoice['invoice_date'] ?? ''),
                    (string) ($invoice['total_amount'] ?? ''),
                    (string) ($invoice['balance_amount'] ?? ''),
                    (string) ($invoice['age_days'] ?? ''),
                    (string) ($invoice['status'] ?? ''),
                    (string) ($invoice['currency_code'] ?? ''),
                ];
            }
        }
    }
}
