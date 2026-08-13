<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryDepartmentRequisitionAuditLogRepositoryInterface
{
    public function write(
        string $requisitionId,
        string $action,
        ?int $actorId = null,
        ?array $changes = null,
        ?array $metadata = null,
    ): void;

    public function listByRequisitionId(string $requisitionId, int $page, int $perPage): array;
}
