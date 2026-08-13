<?php

namespace App\Console\Commands;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class InventoryBackfillOpeningStockFlag extends Command
{
    protected $signature = 'inventory:backfill-opening-stock-flag {--dry-run : Preview changes without writing to the database}';
    protected $description = 'Backfill is_opening_stock flag on the earliest stock movement for each item that has none';

    public function handle(): void
    {
        $isDryRun = $this->option('dry-run');

        $this->info('Starting opening stock flag backfill...');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE — no data will be written.');
        }

        $stats = [
            'items_scanned' => 0,
            'items_with_movements' => 0,
            'items_already_flagged' => 0,
            'items_marked' => 0,
            'items_skipped' => 0,
        ];

        $items = InventoryItemModel::query()
            ->whereHas('stockMovements')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $stats['items_scanned']++;
            $stats['items_with_movements']++;

            $hasOpeningStock = InventoryStockMovementModel::query()
                ->where('item_id', $item->id)
                ->where('is_opening_stock', true)
                ->exists();

            if ($hasOpeningStock) {
                $stats['items_already_flagged']++;
                continue;
            }

            $firstMovement = InventoryStockMovementModel::query()
                ->where('item_id', $item->id)
                ->orderBy('occurred_at')
                ->orderBy('created_at')
                ->first();

            if (! $firstMovement) {
                $stats['items_skipped']++;
                continue;
            }

            $this->line(sprintf(
                '  %s (%s): marking movement %s from %s (qty: %s)',
                $item->item_code ?? $item->id,
                $item->item_name ?? '?',
                $firstMovement->id,
                $firstMovement->occurred_at ?? $firstMovement->created_at,
                $firstMovement->quantity,
            ));

            if (! $isDryRun) {
                $firstMovement->is_opening_stock = true;
                if ($firstMovement->reason_code === null) {
                    $firstMovement->reason_code = 'opening_balance';
                }
                $firstMovement->save();
            }

            $stats['items_marked']++;
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Items scanned', $stats['items_scanned']],
                ['Items with movements', $stats['items_with_movements']],
                ['Already flagged', $stats['items_already_flagged']],
                ['Marked as opening stock', $stats['items_marked']],
                ['Skipped', $stats['items_skipped']],
            ],
        );

        $this->info('Backfill complete.');
    }
}
