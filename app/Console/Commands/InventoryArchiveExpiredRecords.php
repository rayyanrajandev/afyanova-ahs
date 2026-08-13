<?php

namespace App\Console\Commands;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryAccessAuditLogModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalDecisionModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class InventoryArchiveExpiredRecords extends Command
{
    protected $signature = 'inventory:archive-expired-records
        {--type=all : Type of records to archive: audit-logs, decisions, or all}
        {--dry-run : Preview records that would be archived without archiving them}';

    protected $description = 'Archive expired inventory records past regulatory retention period';

    public function handle(): int
    {
        $type = $this->option('type');
        $dryRun = (bool) $this->option('dry-run');
        $exitCode = self::SUCCESS;

        if ($type === 'all' || $type === 'audit-logs') {
            $ok = $this->archiveAuditLogs($dryRun);
            if (!$ok) {
                $exitCode = self::FAILURE;
            }
        }

        if ($type === 'all' || $type === 'decisions') {
            $ok = $this->archiveApprovalDecisions($dryRun);
            if (!$ok) {
                $exitCode = self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->info('Dry run complete. No records were archived.');
        }

        return $exitCode;
    }

    private function archiveAuditLogs(bool $dryRun): bool
    {
        $retentionDays = config('inventory_retention.inventory_access_audit_logs.retention_days', 2190);
        $batchSize = config('inventory_retention.inventory_access_audit_logs.batch_size', 500);
        $cutoff = now()->subDays($retentionDays);

        $candidates = InventoryAccessAuditLogModel::query()
            ->whereNull('archived_at')
            ->where('created_at', '<', $cutoff)
            ->take($batchSize);

        $count = $candidates->count();

        if ($count === 0) {
            $this->info('No expired audit log records to archive.');
            return true;
        }

        $this->info("Found {$count} expired audit log records older than {$retentionDays} days.");

        if ($dryRun) {
            $this->line("[DRY RUN] Would archive {$count} audit log records.");
            return true;
        }

        $archived = $candidates->update(['archived_at' => now()]);

        $this->info("Archived {$archived} audit log records.");

        Log::info('Inventory archive: archived audit log records', [
            'count' => $archived,
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff->toDateTimeString(),
        ]);

        return true;
    }

    private function archiveApprovalDecisions(bool $dryRun): bool
    {
        $retentionDays = config('inventory_retention.inventory_approval_decisions.retention_days', 2190);
        $batchSize = config('inventory_retention.inventory_approval_decisions.batch_size', 500);
        $cutoff = now()->subDays($retentionDays);

        $candidates = InventoryApprovalDecisionModel::query()
            ->whereNull('archived_at')
            ->where('created_at', '<', $cutoff)
            ->take($batchSize);

        $count = $candidates->count();

        if ($count === 0) {
            $this->info('No expired approval decision records to archive.');
            return true;
        }

        $this->info("Found {$count} expired approval decision records older than {$retentionDays} days.");

        if ($dryRun) {
            $this->line("[DRY RUN] Would archive {$count} approval decision records.");
            return true;
        }

        $archived = $candidates->update(['archived_at' => now()]);

        $this->info("Archived {$archived} approval decision records.");

        Log::info('Inventory archive: archived approval decision records', [
            'count' => $archived,
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff->toDateTimeString(),
        ]);

        return true;
    }
}
