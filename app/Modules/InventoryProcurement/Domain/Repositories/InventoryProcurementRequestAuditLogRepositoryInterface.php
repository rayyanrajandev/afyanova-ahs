<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryProcurementRequestAuditLogRepositoryInterface
{
    public function write(
        string $inventoryProcurementRequestId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByRequestId(
        string $inventoryProcurementRequestId,
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
