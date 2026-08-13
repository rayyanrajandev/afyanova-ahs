<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;
use Illuminate\Support\Facades\DB;

class GetLowStockReportUseCase
{
    public function __construct(
        private readonly InventoryItemModel $model,
        private readonly ReportQueryHelper $helper,
    ) {}

    public function execute(array $filters): array
    {
        $query = $this->model->newQuery();
        $this->helper->applyPlatformScopeIfEnabled($query);
        $this->helper->applyItemFilters($query, $filters);
        $query->where('category', 'pharmaceutical');

        $query
            ->where('current_stock', '>', 0)
            ->whereColumn('current_stock', '<=', 'reorder_level');

        $paginator = $query
            ->select(['id', 'item_code', 'item_name', 'category', 'current_stock', 'reorder_level', 'unit', 'manufacturer'])
            ->orderBy(DB::raw('current_stock / NULLIF(reorder_level, 0)'))
            ->paginate(perPage: (int) ($filters['perPage'] ?? 50), page: (int) ($filters['page'] ?? 1));

        $data = array_map(function ($item) {
            $reorder = (float) ($item->reorder_level ?? 1);
            return [
                'id' => $item->id,
                'itemCode' => $item->item_code,
                'itemName' => $item->item_name,
                'category' => $item->category,
                'currentStock' => (float) $item->current_stock,
                'reorderLevel' => $reorder,
                'unit' => $item->unit,
                'stockRatio' => round((float) $item->current_stock / max($reorder, 1), 2),
                'manufacturer' => $item->manufacturer,
            ];
        }, $paginator->items());

        return [
            'data' => $data,
            'meta' => $this->helper->paginatorMeta($paginator),
        ];
    }
}
