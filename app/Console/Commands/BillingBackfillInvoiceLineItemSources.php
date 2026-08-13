<?php

namespace App\Console\Commands;

use App\Modules\Billing\Infrastructure\Models\BillingInvoiceLineItemSourceModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * billing-financial-state-remediation-plan.md, Phase 1. One-time backfill:
 * populates billing_invoice_line_item_sources from every existing invoice's
 * line_items JSON, so the new indexed table (which EloquentBillingInvoiceRepository
 * keeps in sync going forward, on every create/update) has correct history for
 * invoices created before this migration. Run once at deploy time, before relying
 * on the new table as the sole source for "is this already invoiced."
 *
 * Idempotent: clears and re-inserts each invoice's rows, so re-running is safe.
 */
class BillingBackfillInvoiceLineItemSources extends Command
{
    protected $signature = 'billing:backfill-invoice-line-item-sources {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Phase 1: backfill billing_invoice_line_item_sources from existing invoices\' line_items';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->info('Starting billing_invoice_line_item_sources backfill...');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE — no data will be written.');
        }

        $invoicesScanned = 0;
        $sourcesLinked = 0;

        BillingInvoiceModel::query()
            ->orderBy('id')
            ->chunkById(200, function ($invoices) use ($isDryRun, &$invoicesScanned, &$sourcesLinked): void {
                foreach ($invoices as $invoice) {
                    $invoicesScanned++;

                    $rows = [];
                    foreach (($invoice->line_items ?? []) as $index => $lineItem) {
                        if (! is_array($lineItem)) {
                            continue;
                        }

                        $sourceWorkflowKind = trim((string) ($lineItem['sourceWorkflowKind'] ?? ''));
                        $sourceWorkflowId = trim((string) ($lineItem['sourceWorkflowId'] ?? ''));
                        if ($sourceWorkflowKind === '' || $sourceWorkflowId === '') {
                            continue;
                        }

                        $rows[] = [
                            'id' => (string) Str::orderedUuid(),
                            'billing_invoice_id' => $invoice->id,
                            'source_workflow_kind' => strtolower($sourceWorkflowKind),
                            'source_workflow_id' => $sourceWorkflowId,
                            'line_item_index' => $index,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    if ($rows === []) {
                        continue;
                    }

                    $sourcesLinked += count($rows);

                    if ($isDryRun) {
                        continue;
                    }

                    BillingInvoiceLineItemSourceModel::query()
                        ->where('billing_invoice_id', $invoice->id)
                        ->delete();
                    BillingInvoiceLineItemSourceModel::query()->insert($rows);
                }
            });

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Invoices scanned', $invoicesScanned],
                ['Line item sources linked', $sourcesLinked],
            ],
        );

        $this->info($isDryRun ? 'Dry run complete.' : 'Backfill complete.');

        return self::SUCCESS;
    }
}
