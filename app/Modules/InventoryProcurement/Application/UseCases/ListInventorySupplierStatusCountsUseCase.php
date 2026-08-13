<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierRepositoryInterface;

class ListInventorySupplierStatusCountsUseCase
{
    public function __construct(private readonly InventorySupplierRepositoryInterface $inventorySupplierRepository) {}

    public function execute(array $filters): array
    {
        $countryCode = isset($filters['countryCode']) ? strtoupper(trim((string) $filters['countryCode'])) : null;
        $countryCode = $countryCode === '' ? null : $countryCode;

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->inventorySupplierRepository->statusCounts(
            query: $query,
            countryCode: $countryCode,
        );
    }
}

