<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;
use Illuminate\Support\Carbon;

class GetExpiredReportUseCase
{
    public function __construct(
        private readonly InventoryBatchModel $model,
        private readonly ReportQueryHelper $helper,
    ) {}

    public function execute(array $filters): array
    {
        $now = Carbon::now();

        $query = $this->model->newQuery()
            ->leftJoin('inventory_items', 'inventory_batches.item_id', '=', 'inventory_items.id')
            ->whereNotNull('inventory_batches.expiry_date')
            ->where('inventory_batches.expiry_date', '<', $now)
            ->where('inventory_batches.quantity', '>', 0)
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
            ->orderBy('inventory_batches.expiry_date', 'desc')
            ->paginate(perPage: (int) ($filters['perPage'] ?? 50), page: (int) ($filters['page'] ?? 1));

        $totalValue = 0;
        $data = array_map(function ($batch) use ($now, &$totalValue) {
            $value = $batch->unit_cost ? round((float) $batch->quantity * (float) $batch->unit_cost, 2) : null;
            if ($value !== null) {
                $totalValue += $value;
            }
            return [
                'id' => $batch->id,
                'itemId' => $batch->item_id,
                'itemCode' => $batch->item_code,
                'itemName' => $batch->item_name,
                'batchNumber' => $batch->batch_number,
                'expiryDate' => $batch->expiry_date?->toDateString(),
                'quantity' => (float) $batch->quantity,
                'unitCost' => $batch->unit_cost ? (float) $batch->unit_cost : null,
                'estimatedValue' => $value,
                'daysSinceExpiry' => (int) $now->diffInDays(Carbon::parse($batch->expiry_date)),
                'warehouseId' => $batch->warehouse_id,
            ];
        }, $paginator->items());

        return [
            'data' => $data,
            'meta' => $this->helper->paginatorMeta($paginator),
            'summary' => [
                'totalCount' => count($data),
                'totalValue' => round($totalValue, 2),
            ],
        ];
    }
}
