<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryWarehouseRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByWarehouseCodeInScope(
        string $warehouseCode,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool;

    public function search(
        ?string $query,
        ?string $status,
        ?string $warehouseType,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $warehouseType
    ): array;
}

