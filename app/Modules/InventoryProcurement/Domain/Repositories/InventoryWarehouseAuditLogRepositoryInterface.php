<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryWarehouseAuditLogRepositoryInterface
{
    public function write(
        string $inventoryWarehouseId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByWarehouseId(
        string $inventoryWarehouseId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
    ): array;
}

