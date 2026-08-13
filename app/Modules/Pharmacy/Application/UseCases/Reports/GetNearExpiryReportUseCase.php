<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;
use Illuminate\Support\Carbon;

class GetNearExpiryReportUseCase
{
    public function __construct(
        private readonly InventoryBatchModel $model,
        private readonly ReportQueryHelper $helper,
    ) {}

    public function execute(array $filters): array
    {
        $warningDays = (int) ($filters['warningDays'] ?? 90);
        $criticalDays = (int) ($filters['criticalDays'] ?? 30);
        $now = Carbon::now();

        $query = $this->model->newQuery()
            ->leftJoin('inventory_items', 'inventory_batches.item_id', '=', 'inventory_items.id')
            ->whereNotNull('inventory_batches.expiry_date')
            ->where('inventory_batches.quantity', '>', 0)
            ->where('inventory_batches.expiry_date', '>', $now)
            ->where('inventory_batches.expiry_date', '<=', $now->copy()->addDays($warningDays))
            ->where('inventory_items.category', 'pharmaceutical');

        $this->helper->applyPlatformScopeIfEnabled($query);

        if ($itemId = ($filters['itemId'] ?? null)) {
            $query->where('inventory_batches.item_id', $itemId);
        }
        if ($warehouseId = ($filters['warehouseId'] ?? null)) {
            $query->where('inventory_batches.warehouse_id', $warehouseId);
        }

        $paginator = $query
            ->select([
                'inventory_batches.*',
                'inventory_items.item_code',
                'inventory_items.item_name',
            ])
            ->orderBy('inventory_batches.expiry_date')
            ->paginate(perPage: (int) ($filters['perPage'] ?? 50), page: (int) ($filters['page'] ?? 1));

        $data = array_map(function ($batch) use ($now, $criticalDays) {
            $daysUntil = (int) $now->diffInDays(Carbon::parse($batch->expiry_date), false);
            return [
                'id' => $batch->id,
                'itemId' => $batch->item_id,
                'itemCode' => $batch->item_code,
                'itemName' => $batch->item_name,
                'batchNumber' => $batch->batch_number,
                'lotNumber' => $batch->lot_number,
                'expiryDate' => $batch->expiry_date?->toDateString(),
                'quantity' => (float) $batch->quantity,
                'unitCost' => $batch->unit_cost ? (float) $batch->unit_cost : null,
                'estimatedValue' => $batch->unit_cost ? round((float) $batch->quantity * (float) $batch->unit_cost, 2) : null,
                'daysUntilExpiry' => $daysUntil,
                'urgency' => $daysUntil <= $criticalDays ? 'critical' : 'warning',
                'warehouseId' => $batch->warehouse_id,
            ];
        }, $paginator->items());

        return [
            'data' => $data,
            'meta' => $this->helper->paginatorMeta($paginator),
            'summary' => [
                'criticalCount' => count(array_filter($data, fn ($d) => $d['urgency'] === 'critical')),
                'warningCount' => count(array_filter($data, fn ($d) => $d['urgency'] === 'warning')),
            ],
        ];
    }
}
