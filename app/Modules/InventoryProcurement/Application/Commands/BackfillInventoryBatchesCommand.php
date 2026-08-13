<?php

namespace App\Modules\InventoryProcurement\Application\Commands;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockReservationModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillInventoryBatchesCommand extends Command
{
    protected $signature = 'inventory:backfill-batches
        {--dry-run : Preview the backfill without writing anything}
        {--item-id= : Only backfill the specified item UUID}
        {--batch-prefix=BAT- : Prefix for generated internal batch numbers}';

    protected $description = 'Create batch records for inventory items that have stock but no batch records, so the hybrid batch system covers all existing stock.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $specificItemId = $this->option('item-id');
        $batchPrefix = (string) $this->option('batch-prefix');

        $query = InventoryItemModel::query()
            ->where('current_stock', '>', 0)
            ->whereRaw('(SELECT COUNT(*) FROM inventory_batches WHERE inventory_batches.item_id = inventory_items.id) = 0');

        if ($specificItemId !== null) {
            $query->where('id', $specificItemId);
        }

        $items = $query->get();
        $totalItems = $items->count();

        if ($totalItems === 0) {
            $this->info('No items found that need batch backfill.');

            return self::SUCCESS;
        }

        $this->info("Found {$totalItems} item(s) with stock but no batch records.");

        if ($dryRun) {
            $this->warn('DRY RUN — no records will be created.');
        }

        $created = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $stock = round((float) ($item->current_stock ?? 0), 3);

            if ($stock <= 0) {
                $skipped++;

                continue;
            }

            $this->line(sprintf(
                '  %s: stock=%.3f %s',
                $item->item_name ?? $item->id,
                $stock,
                $item->unit ?? '',
            ));

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($item, $stock, $batchPrefix): void {
                $internalBatchNumber = $this->generateInternalBatchNumber($batchPrefix);

                $batch = InventoryBatchModel::query()->create([
                    'tenant_id' => $item->tenant_id,
                    'facility_id' => $item->facility_id,
                    'item_id' => $item->id,
                    'internal_batch_number' => $internalBatchNumber,
                    'batch_number' => null,
                    'quantity' => $stock,
                    'warehouse_id' => $item->default_warehouse_id,
                    'unit_cost' => null,
                    'status' => 'available',
                    'notes' => 'Backfilled from existing stock during hybrid batch migration.',
                ]);

                InventoryStockReservationModel::query()
                    ->where('item_id', $item->id)
                    ->whereNull('batch_id')
                    ->update(['batch_id' => $batch->id, 'internal_batch_number' => $internalBatchNumber]);
            });

            $created++;
        }

        if (! $dryRun) {
            $this->info("Created batch records for {$created} item(s). Skipped {$skipped} item(s) with zero stock.");
        }

        return self::SUCCESS;
    }

    private function generateInternalBatchNumber(string $prefix): string
    {
        $date = now()->format('Ymd');
        $searchPrefix = $prefix . $date . '-';
        $lastBatch = InventoryBatchModel::query()
            ->where('internal_batch_number', 'like', $searchPrefix . '%')
            ->orderBy('internal_batch_number', 'desc')
            ->first();

        if ($lastBatch) {
            $lastSeq = (int) Str::after($lastBatch->internal_batch_number, $searchPrefix);
            $nextSeq = $lastSeq + 1;
        } else {
            $nextSeq = 1;
        }

        return $searchPrefix . str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);
    }
}
