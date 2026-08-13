<?php

namespace App\Jobs;

use App\Modules\Billing\Application\UseCases\ListBillingInvoiceAuditLogsUseCase;
use App\Modules\Laboratory\Application\UseCases\ListLaboratoryOrderAuditLogsUseCase;
use App\Modules\Pharmacy\Application\UseCases\ListPharmacyOrderAuditLogsUseCase;
use App\Modules\Platform\Infrastructure\Models\AuditExportJobModel;
use App\Support\Exports\BrandedCsvExportManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAuditExportCsvJob implements ShouldQueue
{
    use Queueable;

    public const MODULE_BILLING = 'billing-invoice-audit';

    public const MODULE_LABORATORY = 'laboratory-order-audit';

    public const MODULE_PHARMACY = 'pharmacy-order-audit';

    private const PER_PAGE = 100;

    /**
     * @param  string  $auditExportJobId
     */
    public function __construct(
        private readonly string $auditExportJobId,
    ) {}

    public function handle(
        ListBillingInvoiceAuditLogsUseCase $billingUseCase,
        ListLaboratoryOrderAuditLogsUseCase $laboratoryUseCase,
        ListPharmacyOrderAuditLogsUseCase $pharmacyUseCase,
        BrandedCsvExportManager $csvExports,
    ): void {
        $auditExportJob = AuditExportJobModel::query()->find($this->auditExportJobId);
        if (! $auditExportJob) {
            return;
        }

        $updated = AuditExportJobModel::query()
            ->whereKey($auditExportJob->getKey())
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

        $auditExportJob->refresh();
        $filters = is_array($auditExportJob->filters) ? $auditExportJob->filters : [];

        try {
            $fetchPage = match ($auditExportJob->module) {
                self::MODULE_BILLING => fn (int $page): ?array => $billingUseCase->execute(
                    billingInvoiceId: $auditExportJob->target_resource_id,
                    filters: array_merge($filters, ['page' => $page, 'perPage' => self::PER_PAGE]),
                ),
                self::MODULE_LABORATORY => fn (int $page): ?array => $laboratoryUseCase->execute(
                    laboratoryOrderId: $auditExportJob->target_resource_id,
                    filters: array_merge($filters, ['page' => $page, 'perPage' => self::PER_PAGE]),
                ),
                self::MODULE_PHARMACY => fn (int $page): ?array => $pharmacyUseCase->execute(
                    pharmacyOrderId: $auditExportJob->target_resource_id,
                    filters: array_merge($filters, ['page' => $page, 'perPage' => self::PER_PAGE]),
                ),
                default => null,
            };

            if (! is_callable($fetchPage)) {
                throw new \RuntimeException('Unsupported audit export module.');
            }

            $firstPage = $fetchPage(1);
            if ($firstPage === null) {
                throw new \RuntimeException('Unable to locate the target resource for export.');
            }

            $prefix = match ($auditExportJob->module) {
                self::MODULE_BILLING => 'billing',
                self::MODULE_LABORATORY => 'laboratory',
                self::MODULE_PHARMACY => 'pharmacy',
                default => 'audit',
            };

            $safeTargetId = preg_replace('/[^A-Za-z0-9_-]/', '_', $auditExportJob->target_resource_id) ?: 'resource';
            $fileName = $csvExports->makeBrandedFilename(
                sprintf('%s_audit_%s_%s', $prefix, $safeTargetId, now()->format('Ymd_His'))
            );
            $filePath = sprintf('audit-exports/%s.csv', $auditExportJob->getKey());

            $rowCount = $this->writeCsvFile($filePath, $firstPage, $fetchPage, $csvExports);

            $auditExportJob->fill([
                'status' => 'completed',
                'file_path' => $filePath,
                'file_name' => $fileName,
                'row_count' => $rowCount,
                'completed_at' => now(),
                'failed_at' => null,
                'error_message' => null,
            ])->save();
        } catch (\Throwable $exception) {
            $auditExportJob->fill([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();
        }
    }

    /**
     * @param  callable(int): ?array  $fetchPage
     */
    private function writeCsvFile(
        string $filePath,
        array $firstPage,
        callable $fetchPage,
        BrandedCsvExportManager $csvExports,
    ): int
    {
        return $csvExports->writePaginatedAuditFile(
            filePath: $filePath,
            firstPage: $firstPage,
            fetchPage: $fetchPage,
            throwOnMissingPage: true,
        );
    }
}
