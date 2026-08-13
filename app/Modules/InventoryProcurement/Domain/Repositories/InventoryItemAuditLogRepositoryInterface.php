<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryItemAuditLogRepositoryInterface
{
    public function write(
        string $inventoryItemId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByItemId(
        string $inventoryItemId,
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

