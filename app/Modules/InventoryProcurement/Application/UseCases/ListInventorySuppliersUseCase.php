<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventorySupplierStatus;

class ListInventorySuppliersUseCase
{
    public function __construct(private readonly InventorySupplierRepositoryInterface $inventorySupplierRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, InventorySupplierStatus::values(), true)) {
            $status = null;
        }

        $countryCode = isset($filters['countryCode']) ? strtoupper(trim((string) $filters['countryCode'])) : null;
        $countryCode = $countryCode === '' ? null : $countryCode;

        $sortMap = [
            'supplierCode' => 'supplier_code',
            'supplierName' => 'supplier_name',
            'countryCode' => 'country_code',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'supplierName'] ?? 'supplier_name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->inventorySupplierRepository->search(
            query: $query,
            status: $status,
            countryCode: $countryCode,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}

