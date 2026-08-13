<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryBatchRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function listByItemId(string $itemId, int $page, int $perPage, ?string $status = null): array;

    public function findByItemAndBatchNumber(string $itemId, string $batchNumber, ?string $warehouseId = null): ?array;

    public function expiringBatches(int $withinDays, int $page, int $perPage): array;

    public function expiredBatches(int $page, int $perPage): array;
}
