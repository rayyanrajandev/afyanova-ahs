<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDispensingClaimLinkModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;
use Illuminate\Support\Facades\DB;

class GetBatchTrackingUseCase
{
    public function __construct(
        private readonly InventoryBatchModel $model,
        private readonly InventoryDispensingClaimLinkModel $dispensingModel,
        private readonly InventoryStockMovementModel $stockMovementModel,
        private readonly ReportQueryHelper $helper,
    ) {}

    public function execute(array $filters): array
    {
        $query = $this->model->newQuery()
            ->leftJoin('inventory_items', 'inventory_batches.item_id', '=', 'inventory_items.id')
            ->where('inventory_items.category', 'pharmaceutical');

        $this->helper->applyPlatformScopeIfEnabled($query);

        if ($itemId = ($filters['itemId'] ?? null)) {
            $query->where('inventory_batches.item_id', $itemId);
        }
        if ($batchNumber = ($filters['batchNumber'] ?? null)) {
            $query->where(function ($q) use ($batchNumber) {
                $q->where('inventory_batches.batch_number', 'like', '%'.$batchNumber.'%')
                  ->orWhere('inventory_batches.internal_batch_number', 'like', '%'.$batchNumber.'%');
            });
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
            ->orderBy('inventory_batches.created_at', 'desc')
            ->paginate(perPage: (int) ($filters['perPage'] ?? 50), page: (int) ($filters['page'] ?? 1));

        $batchIds = array_map(fn ($b) => $b->id, $paginator->items());

        $dispensedFromMovements = $this->stockMovementModel->newQuery()
            ->whereIn('batch_id', $batchIds)
            ->where('movement_type', 'issue')
            ->select('batch_id', DB::raw('SUM(ABS(quantity_delta)) as total_dispensed'), DB::raw('COUNT(*) as movement_count'))
            ->groupBy('batch_id')
            ->pluck('total_dispensed', 'batch_id');

        $dispensedFromClaims = $this->dispensingModel->newQuery()
            ->whereIn('batch_id', $batchIds)
            ->select('batch_id', DB::raw('SUM(quantity_dispensed) as total_dispensed'), DB::raw('COUNT(*) as dispense_count'))
            ->groupBy('batch_id')
            ->pluck('total_dispensed', 'batch_id');

        $data = array_map(function ($batch) use ($dispensedFromMovements, $dispensedFromClaims) {
            $fromMovements = (float) ($dispensedFromMovements[$batch->id] ?? 0);
            $fromClaims = (float) ($dispensedFromClaims[$batch->id] ?? 0);
            $dispensed = max($fromMovements, $fromClaims);
            return [
                'id' => $batch->id,
                'itemId' => $batch->item_id,
                'itemCode' => $batch->item_code,
                'itemName' => $batch->item_name,
                'internalBatchNumber' => $batch->internal_batch_number,
                'batchNumber' => $batch->batch_number,
                'lotNumber' => $batch->lot_number,
                'manufactureDate' => $batch->manufacture_date?->toDateString(),
                'expiryDate' => $batch->expiry_date?->toDateString(),
                'receivedQuantity' => round((float) $batch->quantity + $dispensed, 3),
                'currentQuantity' => (float) $batch->quantity,
                'dispensedQuantity' => $dispensed,
                'unitCost' => $batch->unit_cost ? (float) $batch->unit_cost : null,
                'warehouseId' => $batch->warehouse_id,
                'supplierId' => $batch->supplier_id,
                'status' => $batch->status,
            ];
        }, $paginator->items());

        return [
            'data' => $data,
            'meta' => $this->helper->paginatorMeta($paginator),
        ];
    }
}
