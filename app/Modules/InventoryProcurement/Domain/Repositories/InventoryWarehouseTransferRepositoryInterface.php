<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryWarehouseTransferRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function search(
        ?string $query,
        ?string $status,
        ?string $varianceReviewStatus,
        ?string $sourceWarehouseId,
        ?string $destinationWarehouseId,
        int $page,
        int $perPage
    ): array;
}
