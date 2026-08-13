<?php

namespace App\Modules\Platform\Domain\Repositories;

interface FacilityResourceAuditLogRepositoryInterface
{
    public function write(
        string $facilityResourceId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByResourceId(
        string $facilityResourceId,
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

