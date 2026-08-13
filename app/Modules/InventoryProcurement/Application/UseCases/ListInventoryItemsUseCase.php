<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;

class ListInventoryItemsUseCase
{
    public function __construct(private readonly InventoryItemRepositoryInterface $inventoryItemRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $stockState = isset($filters['stockState']) ? strtolower(trim((string) $filters['stockState'])) : null;
        if (! in_array($stockState, ['out_of_stock', 'low_stock', 'healthy'], true)) {
            $stockState = null;
        }

        $sortMap = [
            'itemCode' => 'item_code',
            'itemName' => 'item_name',
            'category' => 'category',
            'currentStock' => 'current_stock',
            'reorderLevel' => 'reorder_level',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $filters['sortBy'] ?? 'itemName';
        $sortBy = $sortMap[$sortBy] ?? 'item_name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $category = isset($filters['category']) ? trim((string) $filters['category']) : null;
        $category = $category === '' ? null : $category;

        $subcategory = isset($filters['subcategory']) ? trim((string) $filters['subcategory']) : null;
        $subcategory = $subcategory === '' ? null : $subcategory;

        $requestingDepartmentId = isset($filters['requestingDepartmentId']) ? trim((string) $filters['requestingDepartmentId']) : null;
        $requestingDepartmentId = $requestingDepartmentId === '' ? null : $requestingDepartmentId;

        return $this->inventoryItemRepository->search(
            query: $query,
            category: $category,
            subcategory: $subcategory,
            requestingDepartmentId: $requestingDepartmentId,
            stockState: $stockState,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
