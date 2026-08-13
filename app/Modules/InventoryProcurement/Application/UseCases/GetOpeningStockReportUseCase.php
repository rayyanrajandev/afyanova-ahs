<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use Illuminate\Database\Eloquent\Builder;

class GetOpeningStockReportUseCase
{
    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $sortBy = in_array($filters['sortBy'] ?? 'itemName', ['itemName', 'itemCode', 'quantity', 'occurredAt'], true)
            ? $filters['sortBy']
            : 'itemName';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $sortColumn = match ($sortBy) {
            'itemName' => 'inventory_items.item_name',
            'itemCode' => 'inventory_items.item_code',
            'quantity' => 'inventory_stock_movements.quantity',
            'occurredAt' => 'inventory_stock_movements.occurred_at',
            default => 'inventory_items.item_name',
        };

        $queryBuilder = InventoryStockMovementModel::query()
            ->select([
                'inventory_stock_movements.id',
                'inventory_stock_movements.item_id',
                'inventory_stock_movements.quantity as opening_stock_quantity',
                'inventory_stock_movements.occurred_at as opening_stock_occurred_at',
                'inventory_stock_movements.reason',
                'inventory_stock_movements.reason_code',
                'inventory_stock_movements.superseded_by_id',
                'inventory_stock_movements.created_at',
                'inventory_items.item_code',
                'inventory_items.item_name',
                'inventory_items.category',
                'inventory_items.unit',
                'inventory_items.current_stock',
                'inventory_items.reorder_level',
            ])
            ->join('inventory_items', 'inventory_stock_movements.item_id', '=', 'inventory_items.id')
            ->where('inventory_stock_movements.is_opening_stock', true)
            ->whereNull('inventory_stock_movements.superseded_by_id')
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nested) use ($like): void {
                    $nested
                        ->where('inventory_items.item_code', 'like', $like)
                        ->orWhere('inventory_items.item_name', 'like', $like)
                        ->orWhere('inventory_items.category', 'like', $like);
                });
            })
            ->orderBy($sortColumn, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        $data = array_map(function (mixed $row): array {
            $item = $row instanceof \Illuminate\Database\Eloquent\Model ? $row->toArray() : (array) $row;
            return [
                'id' => $item['id'] ?? null,
                'itemId' => $item['item_id'] ?? null,
                'itemCode' => $item['item_code'] ?? null,
                'itemName' => $item['item_name'] ?? null,
                'category' => $item['category'] ?? null,
                'unit' => $item['unit'] ?? null,
                'openingStockQuantity' => (float) ($item['opening_stock_quantity'] ?? 0),
                'openingStockOccurredAt' => $item['opening_stock_occurred_at'] ?? null,
                'currentStock' => (float) ($item['current_stock'] ?? 0),
                'reorderLevel' => (float) ($item['reorder_level'] ?? 0),
                'reason' => $item['reason'] ?? null,
                'reasonCode' => $item['reason_code'] ?? null,
                'isSuperseded' => ($item['superseded_by_id'] ?? null) !== null,
            ];
        }, $paginator->items());

        return [
            'data' => $data,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }
}
