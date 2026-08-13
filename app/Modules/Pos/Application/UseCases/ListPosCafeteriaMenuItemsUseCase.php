<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Pos\Domain\Repositories\PosCafeteriaMenuItemRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosCatalogItemStatus;

class ListPosCafeteriaMenuItemsUseCase
{
    public function __construct(
        private readonly PosCafeteriaMenuItemRepositoryInterface $posCafeteriaMenuItemRepository,
    ) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $sortMap = [
            'itemCode' => 'item_code',
            'itemName' => 'item_name',
            'category' => 'category',
            'unitPrice' => 'unit_price',
            'status' => 'status',
            'sortOrder' => 'sort_order',
            'updatedAt' => 'updated_at',
            'createdAt' => 'created_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'sortOrder'] ?? 'sort_order';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $status = in_array($status, PosCatalogItemStatus::values(), true) ? $status : null;

        $category = isset($filters['category']) ? trim((string) $filters['category']) : null;
        $category = $category === '' ? null : $category;

        return $this->posCafeteriaMenuItemRepository->search(
            query: $query,
            status: $status,
            category: $category,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
