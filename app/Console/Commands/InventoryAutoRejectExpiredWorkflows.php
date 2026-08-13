<?php

namespace App\Console\Commands;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalWorkflowInstanceModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class InventoryAutoRejectExpiredWorkflows extends Command
{
    protected $signature = 'inventory:auto-reject-expired-workflows
        {--dry-run : Preview workflows that would be auto-rejected without executing}';

    protected $description = 'Auto-reject approval workflows that have exceeded their timeout';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $exitCode = self::SUCCESS;

        $expired = InventoryApprovalWorkflowInstanceModel::where('status', 'in_progress')
            ->whereNotNull('timeout_at')
            ->where('timeout_at', '<', now())
            ->whereNull('auto_rejected_at')
            ->get();

        $count = $expired->count();

        if ($count === 0) {
            $this->info('No expired approval workflows found.');
            return self::SUCCESS;
        }

        $this->info("Found {$count} expired approval workflow(s).");

        foreach ($expired as $instance) {
            if ($dryRun) {
                $this->line(
                    "  [DRY-RUN] Would auto-reject: {$instance->id}"
                    . " (requisition: {$instance->requisition_id},"
                    . " timed out at: {$instance->timeout_at})"
                );
                continue;
            }

            try {
                $instance->update([
                    'status' => 'rejected',
                    'current_step' => 'rejected',
                    'auto_rejected_at' => now(),
                    'rejected_at' => now(),
                ]);

                $instance->requisition->update(['status' => 'rejected']);

                Log::info('Approval workflow auto-rejected due to timeout', [
                    'workflow_instance_id' => $instance->id,
                    'requisition_id' => $instance->requisition_id,
                    'timeout_at' => $instance->timeout_at,
                    'auto_rejected_at' => now(),
                ]);

                $this->line("  Auto-rejected: {$instance->id}");
            } catch (\Exception $e) {
                $this->error("  Failed to auto-reject {$instance->id}: {$e->getMessage()}");
                Log::error('Auto-rejection failed', [
                    'workflow_instance_id' => $instance->id,
                    'error' => $e->getMessage(),
                ]);
                $exitCode = self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->info('Dry run complete. No workflows were auto-rejected.');
        } else {
            $this->info("Processed {$count} expired workflow(s).");
        }

        return $exitCode;
    }
}
