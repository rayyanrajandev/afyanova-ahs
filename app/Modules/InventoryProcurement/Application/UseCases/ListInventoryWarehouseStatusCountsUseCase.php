<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseRepositoryInterface;

class ListInventoryWarehouseStatusCountsUseCase
{
    public function __construct(private readonly InventoryWarehouseRepositoryInterface $inventoryWarehouseRepository) {}

    public function execute(array $filters): array
    {
        $warehouseType = isset($filters['warehouseType']) ? trim((string) $filters['warehouseType']) : null;
        $warehouseType = $warehouseType === '' ? null : $warehouseType;

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->inventoryWarehouseRepository->statusCounts(
            query: $query,
            warehouseType: $warehouseType,
        );
    }
}

