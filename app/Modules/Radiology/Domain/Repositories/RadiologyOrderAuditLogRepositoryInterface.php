<?php

namespace App\Modules\Radiology\Domain\Repositories;

interface RadiologyOrderAuditLogRepositoryInterface
{
    public function write(
        string $radiologyOrderId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByRadiologyOrderId(
        string $radiologyOrderId,
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
