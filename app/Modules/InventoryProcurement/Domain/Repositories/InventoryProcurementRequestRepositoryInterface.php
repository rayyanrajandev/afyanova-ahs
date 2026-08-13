<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryProcurementRequestRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByRequestNumber(string $requestNumber): bool;

    /**
     * @param  array<int, string>  $lineIds
     * @return array<string, array<string, mixed>>
     */
    public function latestBySourceDepartmentRequisitionLineIds(array $lineIds): array;

    public function search(
        ?string $query,
        ?string $status,
        ?string $itemId,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;
}
