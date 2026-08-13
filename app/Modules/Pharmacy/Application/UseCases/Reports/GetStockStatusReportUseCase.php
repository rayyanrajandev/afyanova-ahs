<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;
use Illuminate\Support\Carbon;

class GetStockStatusReportUseCase
{
    public function __construct(
        private readonly InventoryItemModel $model,
        private readonly InventoryStockMovementModel $stockMovementModel,
        private readonly ReportQueryHelper $helper,
    ) {}

    public function execute(array $filters): array
    {
        $query = $this->model->newQuery();
        $this->helper->applyPlatformScopeIfEnabled($query);
        $this->helper->applyItemFilters($query, $filters);
        $query->where('category', 'pharmaceutical');

        $paginator = $query
            ->select(['id', 'item_code', 'item_name', 'category', 'current_stock', 'reorder_level', 'max_stock_level', 'unit', 'default_warehouse_id', 'manufacturer', 'status'])
            ->orderBy('item_name')
            ->paginate(perPage: (int) ($filters['perPage'] ?? 50), page: (int) ($filters['page'] ?? 1));

        $itemIds = array_map(fn ($item) => $item->id, $paginator->items());
        $reservedQuantities = $this->reservedQuantitiesByItem($itemIds);
        $lastMovementDates = $this->lastMovementDatesByItem($itemIds);

        $data = array_map(function ($item) use ($reservedQuantities, $lastMovementDates) {
            $reserved = $reservedQuantities[$item->id] ?? 0;
            $available = (float) $item->current_stock - $reserved;
            $reorder = (float) ($item->reorder_level ?? 0);

            return [
                'id' => $item->id,
                'itemCode' => $item->item_code,
                'itemName' => $item->item_name,
                'category' => $item->category,
                'currentStock' => (float) $item->current_stock,
                'reservedStock' => $reserved,
                'availableStock' => max($available, 0),
                'reorderLevel' => $reorder,
                'maxStockLevel' => $item->max_stock_level ? (float) $item->max_stock_level : null,
                'unit' => $item->unit,
                'stockState' => $this->helper->stockState((float) $item->current_stock, $reorder),
                'manufacturer' => $item->manufacturer,
                'lastMovementDate' => $lastMovementDates[$item->id] ?? null,
                'status' => $item->status,
            ];
        }, $paginator->items());

        return [
            'data' => $data,
            'meta' => $this->helper->paginatorMeta($paginator),
        ];
    }

    private function reservedQuantitiesByItem(array $itemIds): array
    {
        if (empty($itemIds)) return [];

        return $this->stockMovementModel->newQuery()
            ->whereIn('item_id', $itemIds)
            ->where('movement_type', 'issue')
            ->select('item_id', \Illuminate\Support\Facades\DB::raw('COALESCE(SUM(ABS(quantity_delta)), 0) as total_issued'))
            ->groupBy('item_id')
            ->pluck('total_issued', 'item_id')
            ->toArray();
    }

    private function lastMovementDatesByItem(array $itemIds): array
    {
        if (empty($itemIds)) return [];

        return $this->stockMovementModel->newQuery()
            ->whereIn('item_id', $itemIds)
            ->select('item_id', \Illuminate\Support\Facades\DB::raw('MAX(occurred_at) as last_date'))
            ->groupBy('item_id')
            ->pluck('last_date', 'item_id')
            ->map(fn ($d) => $d ? Carbon::parse($d)->toIso8601String() : null)
            ->toArray();
    }
}
