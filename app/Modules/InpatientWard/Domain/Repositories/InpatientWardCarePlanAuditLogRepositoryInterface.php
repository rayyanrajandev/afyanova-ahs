<?php

namespace App\Modules\InpatientWard\Domain\Repositories;

interface InpatientWardCarePlanAuditLogRepositoryInterface
{
    public function write(
        string $inpatientWardCarePlanId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByCarePlanId(
        string $inpatientWardCarePlanId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;
}

