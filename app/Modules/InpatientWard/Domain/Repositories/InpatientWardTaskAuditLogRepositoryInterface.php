<?php

namespace App\Modules\InpatientWard\Domain\Repositories;

interface InpatientWardTaskAuditLogRepositoryInterface
{
    public function write(
        string $inpatientWardTaskId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByTaskId(
        string $inpatientWardTaskId,
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
