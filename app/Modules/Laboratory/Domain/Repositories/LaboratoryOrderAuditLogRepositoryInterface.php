<?php

namespace App\Modules\Laboratory\Domain\Repositories;

interface LaboratoryOrderAuditLogRepositoryInterface
{
    public function write(
        string $laboratoryOrderId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByLaboratoryOrderId(
        string $laboratoryOrderId,
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
