<?php

namespace App\Modules\Platform\Domain\Repositories;

interface MultiFacilityRolloutAuditLogRepositoryInterface
{
    public function write(
        string $rolloutPlanId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByRolloutPlanId(
        string $rolloutPlanId,
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
