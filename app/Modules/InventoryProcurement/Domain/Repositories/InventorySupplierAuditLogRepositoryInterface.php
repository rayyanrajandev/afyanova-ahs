<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventorySupplierAuditLogRepositoryInterface
{
    public function write(
        string $inventorySupplierId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listBySupplierId(
        string $inventorySupplierId,
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

