<?php

namespace App\Modules\InventoryProcurement\Application\Commands;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryProcurementRequestStatus;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryProcurementRequestModel;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutoReorderCommand extends Command
{
    protected $signature = 'inventory:auto-reorder
        {--dry-run : Show what would be reordered without creating requests}';

    protected $description = 'Automatically create procurement requests for items whose stock has fallen below their reorder level.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();

        // Find active items where current_stock < reorder_level and reorder_level > 0
        $belowReorder = InventoryItemModel::query()
            ->where('status', 'active')
            ->whereNotNull('reorder_level')
            ->where('reorder_level', '>', 0)
            ->whereColumn('current_stock', '<', 'reorder_level')
            ->get();

        if ($belowReorder->isEmpty()) {
            $this->info('No items below reorder level.');
            return self::SUCCESS;
        }

        $this->info("Found {$belowReorder->count()} item(s) below reorder level.");

        $createdCount = 0;
        $skippedCount = 0;
        $rows = [];

        foreach ($belowReorder as $item) {
            // Skip if there's already a pending/approved/ordered procurement request for this item
            $existingRequest = InventoryProcurementRequestModel::query()
                ->where('item_id', $item->id)
                ->where('tenant_id', $item->tenant_id)
                ->whereIn('status', [
                    InventoryProcurementRequestStatus::PENDING_APPROVAL->value,
                    'approved',
                    'ordered',
                ])
                ->exists();

            if ($existingRequest) {
                $skippedCount++;
                $rows[] = [
                    $item->item_code,
                    $item->item_name,
                    (float) $item->current_stock,
                    (float) $item->reorder_level,
                    $item->max_stock_level ? (float) $item->max_stock_level : '—',
                    'SKIPPED (existing request)',
                ];
                continue;
            }

            // Calculate reorder quantity: max_stock_level - current_stock, or reorder_level * 2 - current_stock
            $targetStock = $item->max_stock_level
                ? (float) $item->max_stock_level
                : (float) $item->reorder_level * 2;
            $reorderQty = max(0, $targetStock - (float) $item->current_stock);

            if ($reorderQty <= 0) {
                $skippedCount++;
                continue;
            }

            $rows[] = [
                $item->item_code,
                $item->item_name,
                (float) $item->current_stock,
                (float) $item->reorder_level,
                $item->max_stock_level ? (float) $item->max_stock_level : '—',
                $dryRun ? "WOULD ORDER {$reorderQty}" : "ORDERED {$reorderQty}",
            ];

            if (! $dryRun) {
                InventoryProcurementRequestModel::create([
                    'request_number' => $this->generateRequestNumber(),
                    'tenant_id' => $item->tenant_id,
                    'facility_id' => $item->facility_id,
                    'item_id' => $item->id,
                    'requested_quantity' => $reorderQty,
                    'status' => InventoryProcurementRequestStatus::PENDING_APPROVAL->value,
                    'notes' => "Auto-generated reorder: stock ({$item->current_stock}) below reorder level ({$item->reorder_level}).",
                    'needed_by' => $now->copy()->addDays(
                        $this->estimateLeadTimeDays($item)
                    )->toDateString(),
                ]);

                $createdCount++;
            }
        }

        if (count($rows) > 0) {
            $this->table(
                ['Code', 'Name', 'Current Stock', 'Reorder Level', 'Max Stock', 'Action'],
                $rows
            );
        }

        if ($dryRun) {
            $this->warn("Dry run — no procurement requests created. {$belowReorder->count()} items reviewed, {$skippedCount} skipped.");
        } else {
            $this->info("Created {$createdCount} procurement request(s). Skipped {$skippedCount}.");
            Log::channel('daily')->info('Auto-reorder completed', [
                'created' => $createdCount,
                'skipped' => $skippedCount,
                'total_below_reorder' => $belowReorder->count(),
            ]);
        }

        return self::SUCCESS;
    }

    private function generateRequestNumber(): string
    {
        return 'PRQ-AUTO-' . now()->format('Ymd') . '-' . strtoupper(Str::random(5));
    }

    /**
     * Estimate lead time from supplier lead time tracking if available, otherwise default.
     */
    private function estimateLeadTimeDays(InventoryItemModel $item): int
    {
        if (! $item->default_supplier_id) {
            return 14; // Default 2 weeks
        }

        // Check if supplier has tracked lead times
        $avgLeadTime = \DB::table('inventory_supplier_lead_times')
            ->where('supplier_id', $item->default_supplier_id)
            ->where('item_id', $item->id)
            ->whereNotNull('actual_lead_time_days')
            ->avg('actual_lead_time_days');

        if ($avgLeadTime !== null) {
            return (int) ceil($avgLeadTime * 1.2); // Add 20% buffer
        }

        // Fall back to supplier-level average
        $supplierAvg = \DB::table('inventory_supplier_lead_times')
            ->where('supplier_id', $item->default_supplier_id)
            ->whereNotNull('actual_lead_time_days')
            ->avg('actual_lead_time_days');

        return $supplierAvg !== null ? (int) ceil($supplierAvg * 1.2) : 14;
    }
}
