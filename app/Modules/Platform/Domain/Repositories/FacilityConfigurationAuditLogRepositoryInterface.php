<?php

namespace App\Modules\Platform\Domain\Repositories;

interface FacilityConfigurationAuditLogRepositoryInterface
{
    public function write(
        string $facilityId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByFacilityId(
        string $facilityId,
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
