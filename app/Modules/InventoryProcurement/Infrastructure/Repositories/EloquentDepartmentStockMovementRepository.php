<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\DepartmentStockMovementRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\DepartmentStockMovementModel;

class EloquentDepartmentStockMovementRepository implements DepartmentStockMovementRepositoryInterface
{
    public function create(array $attributes): array
    {
        $movement = DepartmentStockMovementModel::create($attributes);

        return $movement->toArray();
    }

    public function listByBalanceId(string $balanceId, int $page = 1, int $perPage = 20): array
    {
        $paginator = DepartmentStockMovementModel::query()
            ->where('department_stock_balance_id', $balanceId)
            ->orderBy('occurred_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }

    public function listByDepartment(
        string $departmentId,
        ?string $itemId = null,
        ?string $movementType = null,
        int $page = 1,
        int $perPage = 20,
    ): array {
        $query = DepartmentStockMovementModel::query()
            ->where('department_id', $departmentId)
            ->with(['item']);

        if ($itemId) {
            $query->where('item_id', $itemId);
        }

        if ($movementType) {
            $query->where('movement_type', $movementType);
        }

        $paginator = $query->orderBy('occurred_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }
}
