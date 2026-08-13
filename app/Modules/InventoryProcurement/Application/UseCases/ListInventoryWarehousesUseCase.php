<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseStatus;

class ListInventoryWarehousesUseCase
{
    public function __construct(private readonly InventoryWarehouseRepositoryInterface $inventoryWarehouseRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, InventoryWarehouseStatus::values(), true)) {
            $status = null;
        }

        $warehouseType = isset($filters['warehouseType']) ? trim((string) $filters['warehouseType']) : null;
        $warehouseType = $warehouseType === '' ? null : $warehouseType;

        $sortMap = [
            'warehouseCode' => 'warehouse_code',
            'warehouseName' => 'warehouse_name',
            'warehouseType' => 'warehouse_type',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'warehouseName'] ?? 'warehouse_name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->inventoryWarehouseRepository->search(
            query: $query,
            status: $status,
            warehouseType: $warehouseType,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}

