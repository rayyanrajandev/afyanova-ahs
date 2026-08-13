<?php

namespace App\Modules\TheatreProcedure\Domain\Repositories;

interface TheatreProcedureResourceAllocationAuditLogRepositoryInterface
{
    public function write(
        string $allocationId,
        string $theatreProcedureId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByAllocationId(
        string $allocationId,
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
