<?php

namespace App\Jobs;

use App\Modules\Billing\Infrastructure\Integrations\NHIF\NhifRemittanceProcessor;
use App\Modules\Billing\Infrastructure\Models\BillingNhifRemittanceModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Moves NHIF remittance-file reconciliation off the request cycle.
 * BillingNhifRemittanceController::upload() used to parse and reconcile the
 * whole uploaded file in-request (NhifRemittanceProcessor::processFile()) —
 * a real blocking risk for a large remittance file, since reconcile() loops
 * every claim individually inside one DB transaction. The controller now
 * stores the file durably and creates the remittance row as 'pending' before
 * this job runs, following GenerateAuditExportCsvJob's pending->processing
 * idempotency-guard pattern.
 */
class ProcessNhifRemittanceFileJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $remittanceId,
        private readonly string $storedFilePath,
        private readonly string $format,
    ) {}

    public function handle(NhifRemittanceProcessor $processor): void
    {
        $remittance = BillingNhifRemittanceModel::query()->find($this->remittanceId);
        if (! $remittance) {
            return;
        }

        $updated = BillingNhifRemittanceModel::query()
            ->whereKey($remittance->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'processing']);

        if ($updated === 0) {
            return;
        }

        $remittance->refresh();

        try {
            if (! Storage::disk('local')->exists($this->storedFilePath)) {
                throw new \RuntimeException('Uploaded remittance file is missing.');
            }

            $processor->reconcileStoredFile(
                $remittance,
                Storage::disk('local')->path($this->storedFilePath),
                $this->format,
            );
        } catch (\Throwable $exception) {
            Log::error('NHIF remittance job failed', [
                'remittanceId' => $this->remittanceId,
                'error' => $exception->getMessage(),
            ]);

            $remittance->fill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'processed_at' => now(),
            ])->save();
        } finally {
            Storage::disk('local')->delete($this->storedFilePath);
        }
    }
}
