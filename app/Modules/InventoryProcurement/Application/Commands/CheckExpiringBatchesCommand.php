<?php

namespace App\Modules\InventoryProcurement\Application\Commands;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Notifications\InventoryExpiryAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CheckExpiringBatchesCommand extends Command
{
    protected $signature = 'inventory:check-expiring-batches
        {--critical-days=30 : Days threshold for critical expiry alerts}
        {--warning-days=90 : Days threshold for warning expiry alerts}
        {--quarantine-expired : Automatically quarantine expired batches}';

    protected $description = 'Check for expiring and expired inventory batches, send alerts and optionally quarantine expired stock.';

    public function handle(): int
    {
        $criticalDays = (int) $this->option('critical-days');
        $warningDays = (int) $this->option('warning-days');
        $shouldQuarantine = (bool) $this->option('quarantine-expired');

        $now = Carbon::now();

        // 1. Find expired batches (expiry_date <= today, still available)
        $expiredBatches = InventoryBatchModel::query()
            ->where('status', 'available')
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $now)
            ->get();

        // 2. Find critically expiring batches (within critical-days)
        $criticalBatches = InventoryBatchModel::query()
            ->where('status', 'available')
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '>', $now)
            ->where('expiry_date', '<=', $now->copy()->addDays($criticalDays))
            ->get();

        // 3. Find warning-level batches (within warning-days but beyond critical)
        $warningBatches = InventoryBatchModel::query()
            ->where('status', 'available')
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '>', $now->copy()->addDays($criticalDays))
            ->where('expiry_date', '<=', $now->copy()->addDays($warningDays))
            ->get();

        $expiredCount = $expiredBatches->count();
        $criticalCount = $criticalBatches->count();
        $warningCount = $warningBatches->count();

        $this->info("Expiry check completed at {$now->toDateTimeString()}:");
        $this->table(
            ['Category', 'Count'],
            [
                ['Expired (past expiry date)', $expiredCount],
                ["Critical (within {$criticalDays} days)", $criticalCount],
                ["Warning (within {$warningDays} days)", $warningCount],
            ]
        );

        // 4. Quarantine expired batches
        $quarantinedCount = 0;
        if ($shouldQuarantine && $expiredCount > 0) {
            foreach ($expiredBatches as $batch) {
                $batch->update(['status' => 'quarantined']);
                $quarantinedCount++;
            }
            $this->warn("Quarantined {$quarantinedCount} expired batches.");
        }

        // 5. Build alert summary and log it
        if ($expiredCount > 0 || $criticalCount > 0) {
            $alertItems = $this->buildAlertSummary($expiredBatches, $criticalBatches, $warningBatches);

            Log::channel('daily')->warning('Inventory expiry alert', [
                'expired_count' => $expiredCount,
                'critical_count' => $criticalCount,
                'warning_count' => $warningCount,
                'quarantined_count' => $quarantinedCount,
                'items' => $alertItems,
            ]);

            // 6. Notify pharmacy/store managers via mail (route notification to configured address)
            $notifyEmail = config('inventory.expiry_alert_email');
            if ($notifyEmail) {
                Notification::route('mail', $notifyEmail)
                    ->notify(new InventoryExpiryAlertNotification(
                        expiredCount: $expiredCount,
                        criticalCount: $criticalCount,
                        warningCount: $warningCount,
                        quarantinedCount: $quarantinedCount,
                        alertItems: $alertItems,
                    ));
                $this->info("Alert notification sent to {$notifyEmail}");
            }
        } else {
            $this->info('No expired or critically expiring batches found.');
        }

        return self::SUCCESS;
    }

    private function buildAlertSummary($expiredBatches, $criticalBatches, $warningBatches): array
    {
        $itemIds = collect()
            ->merge($expiredBatches->pluck('item_id'))
            ->merge($criticalBatches->pluck('item_id'))
            ->merge($warningBatches->pluck('item_id'))
            ->unique()
            ->values();

        $items = InventoryItemModel::whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        $summary = [];

        foreach ($expiredBatches as $batch) {
            $item = $items->get($batch->item_id);
            $summary[] = [
                'level' => 'expired',
                'item_name' => $item?->item_name ?? 'Unknown',
                'item_code' => $item?->item_code ?? '',
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'quantity' => (float) $batch->quantity,
                'warehouse_id' => $batch->warehouse_id,
            ];
        }

        foreach ($criticalBatches as $batch) {
            $item = $items->get($batch->item_id);
            $summary[] = [
                'level' => 'critical',
                'item_name' => $item?->item_name ?? 'Unknown',
                'item_code' => $item?->item_code ?? '',
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'quantity' => (float) $batch->quantity,
                'days_until_expiry' => (int) now()->diffInDays($batch->expiry_date, false),
                'warehouse_id' => $batch->warehouse_id,
            ];
        }

        foreach ($warningBatches as $batch) {
            $item = $items->get($batch->item_id);
            $summary[] = [
                'level' => 'warning',
                'item_name' => $item?->item_name ?? 'Unknown',
                'item_code' => $item?->item_code ?? '',
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'quantity' => (float) $batch->quantity,
                'days_until_expiry' => (int) now()->diffInDays($batch->expiry_date, false),
                'warehouse_id' => $batch->warehouse_id,
            ];
        }

        return $summary;
    }
}
