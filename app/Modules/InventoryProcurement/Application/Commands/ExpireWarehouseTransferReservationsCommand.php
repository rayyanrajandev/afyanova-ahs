<?php

namespace App\Modules\InventoryProcurement\Application\Commands;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockReservationModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseTransferAuditLogModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireWarehouseTransferReservationsCommand extends Command
{
    protected $signature = 'inventory:expire-warehouse-transfer-reservations
        {--dry-run : Report what would be released without changing records}
        {--json : Output machine-readable JSON summary}';

    protected $description = 'Release expired warehouse transfer stock holds and log the automatic governance action.';

    public function handle(): int
    {
        $now = now();
        $dryRun = (bool) $this->option('dry-run');

        $sourceIds = InventoryStockReservationModel::query()
            ->where('source_type', 'inventory_warehouse_transfer')
            ->where('status', InventoryStockReservationModel::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->groupBy('source_id')
            ->pluck('source_id')
            ->map(static fn (mixed $value): string => (string) $value)
            ->filter()
            ->values()
            ->all();

        $summary = [
            'runAt' => $now->toISOString(),
            'dryRun' => $dryRun,
            'transfersProcessed' => 0,
            'reservationsReleased' => 0,
            'releasedQuantity' => 0.0,
        ];

        foreach ($sourceIds as $sourceId) {
            DB::transaction(function () use ($sourceId, $now, $dryRun, &$summary): void {
                $reservations = InventoryStockReservationModel::query()
                    ->where('source_type', 'inventory_warehouse_transfer')
                    ->where('source_id', $sourceId)
                    ->where('status', InventoryStockReservationModel::STATUS_ACTIVE)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $now)
                    ->lockForUpdate()
                    ->get();

                if ($reservations->isEmpty()) {
                    return;
                }

                $releasedQuantity = round((float) $reservations->sum(static fn (InventoryStockReservationModel $reservation): float => (float) ($reservation->quantity ?? 0)), 3);

                $summary['transfersProcessed']++;
                $summary['reservationsReleased'] += $reservations->count();
                $summary['releasedQuantity'] = round((float) $summary['releasedQuantity'] + $releasedQuantity, 3);

                if ($dryRun) {
                    return;
                }

                foreach ($reservations as $reservation) {
                    $metadata = is_array($reservation->metadata) ? $reservation->metadata : [];

                    $reservation->forceFill([
                        'status' => InventoryStockReservationModel::STATUS_RELEASED,
                        'released_at' => $now,
                        'metadata' => array_merge($metadata, [
                            'releaseReason' => 'Transfer hold expired automatically.',
                            'releaseSource' => 'expired_reservation',
                            'expiredAutoReleasedAt' => $now->toISOString(),
                        ]),
                    ])->save();
                }

                InventoryWarehouseTransferAuditLogModel::query()->create([
                    'transfer_id' => $sourceId,
                    'action' => 'reservation_expired_auto_released',
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'changes' => [
                        'reservationsReleased' => $reservations->count(),
                        'releasedQuantity' => $releasedQuantity,
                    ],
                    'metadata' => [
                        'sourceType' => 'inventory_warehouse_transfer',
                        'releaseSource' => 'expired_reservation',
                        'reservationIds' => $reservations->pluck('id')->values()->all(),
                        'runAt' => $now->toISOString(),
                    ],
                    'created_at' => $now,
                ]);
            });
        }

        if (! $dryRun && $summary['reservationsReleased'] > 0) {
            Log::channel('daily')->info('Warehouse transfer reservations expired automatically.', $summary);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Warehouse transfer hold expiry check completed: %d transfer(s), %d reservation(s), %s unit(s)%s.',
            $summary['transfersProcessed'],
            $summary['reservationsReleased'],
            number_format((float) $summary['releasedQuantity'], 3, '.', ''),
            $dryRun ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }
}
