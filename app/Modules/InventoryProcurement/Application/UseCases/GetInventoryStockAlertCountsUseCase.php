<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;

class GetInventoryStockAlertCountsUseCase
{
    public function __construct(private readonly InventoryItemRepositoryInterface $inventoryItemRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $category = isset($filters['category']) ? trim((string) $filters['category']) : null;
        $category = $category === '' ? null : $category;

        $requestingDepartmentId = isset($filters['requestingDepartmentId']) ? trim((string) $filters['requestingDepartmentId']) : null;
        $requestingDepartmentId = $requestingDepartmentId === '' ? null : $requestingDepartmentId;

        return $this->inventoryItemRepository->stockAlertCounts(
            query: $query,
            category: $category,
            requestingDepartmentId: $requestingDepartmentId,
        );
    }
}
