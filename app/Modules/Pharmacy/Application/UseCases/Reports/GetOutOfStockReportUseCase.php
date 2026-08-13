<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GetOutOfStockReportUseCase
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

        $query->where('current_stock', '<=', 0);

        $paginator = $query
            ->select(['id', 'item_code', 'item_name', 'category', 'current_stock', 'reorder_level', 'unit', 'manufacturer', 'updated_at'])
            ->orderBy('item_name')
            ->paginate(perPage: (int) ($filters['perPage'] ?? 50), page: (int) ($filters['page'] ?? 1));

        $itemIds = array_map(fn ($item) => $item->id, $paginator->items());

        $lastIncomingDates = $this->stockMovementModel->newQuery()
            ->whereIn('item_id', $itemIds)
            ->where('movement_type', 'receive')
            ->select('item_id', DB::raw('MAX(occurred_at) as last_received'))
            ->groupBy('item_id')
            ->pluck('last_received', 'item_id');

        $data = array_map(function ($item) use ($lastIncomingDates) {
            return [
                'id' => $item->id,
                'itemCode' => $item->item_code,
                'itemName' => $item->item_name,
                'category' => $item->category,
                'currentStock' => (float) $item->current_stock,
                'reorderLevel' => (float) ($item->reorder_level ?? 0),
                'unit' => $item->unit,
                'manufacturer' => $item->manufacturer,
                'lastStockedAt' => isset($lastIncomingDates[$item->id]) ? Carbon::parse($lastIncomingDates[$item->id])->toIso8601String() : null,
                'daysOutOfStock' => $item->updated_at ? Carbon::parse($item->updated_at)->diffInDays(Carbon::now()) : null,
            ];
        }, $paginator->items());

        return [
            'data' => $data,
            'meta' => $this->helper->paginatorMeta($paginator),
        ];
    }
}
